# Мониторинг исключений с помощью Graphite и Grafana

## Установка

1. Запускаем docker-контейнеры командой `docker-compose up -d`
2. Логинимся в контейнер `php` командой `docker exec -it php sh`
3. Устанавливаем зависимости командой `composer install`

## Добавляем контроллер с исключением

1. Добавляем класс `App\Service\ExternalService`
    ```php
    <?php
    
    namespace App\Service;
    
    use Exception;
    
    class ExternalService
    {
        private const SUCCESSFUL_RATE = 50;
    
        /**
         * @throws Exception
         */
        public function getName(int $externalEntityId): string
        {
            $isSuccessful = random_int(0, 99) < self::SUCCESSFUL_RATE;
            
            if (!$isSuccessful) {
                throw new Exception('Cannot request name for entity '. $externalEntityId);
            }
            
            return 'External Entity '.$externalEntityId;
        }
    }
    ```
2. Добавляем класс `App\Controller\ExternalController`
   ```php
   <?php
   
   namespace App\Controller;
   
   use App\Service\ExternalService;
   use Exception;
   use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
   use Symfony\Component\HttpFoundation\Request;
   use Symfony\Component\HttpFoundation\Response;
   
   class ExternalController extends AbstractController
   {
       public function __construct(
           private readonly ExternalService $externalService
       ) {
       }
   
       /**
        * @throws Exception
        */
       public function requestExternalService(Request $request): Response
       {
           $externalEntityId = $request->query->get('id');
   
           if (!is_numeric($externalEntityId)) {
               return new Response(null, Response::HTTP_BAD_REQUEST);
           }
   
           return new Response($this->externalService->getName((int)$externalEntityId));
       }
   }
   ```
3. Добавляем путь к контроллеру в файл `config/routes.yaml`
   ```yaml
   external_request:
     path: /external
     controller: App\Controller\ExternalController::requestExternalService
   ```
4. Делаем несколько запросов по адресу `http://localhost:7777/external?id=123`, чтобы получить успешный и ошибочный
   ответы

## Добавляем массовую обработку

1. Добавляем в класс `App\Service\ExternalService` новый метод `getMultipleNames`
    ```php
    /**
     * @return string[]
     * @throws Exception
     */
    public function getMultipleNames(int $startId, int $count): array
    {
        $result = [];
        
        while ($count-- > 0) {
            $result[] = $this->getName($startId++);
        }
        
        return $result;
    }
    ```
2. В классе `App\Controller\ExternalController` исправляем метод `requestExternalService`
    ```php
    /**
     * @throws Exception
     */
    public function requestExternalService(Request $request): Response
    {
        $externalEntityId = $request->query->get('id');
        $count = $request->query->get('count', 1);

        if (!is_numeric($externalEntityId) || !is_numeric($count)) {
            return new Response(null, Response::HTTP_BAD_REQUEST);
        }

        return new Response(implode(
            '; ',
            $this->externalService->getMultipleNames((int)$externalEntityId, (int)$count),
        ));
    }
    ```
3. Делаем несколько запросов по адресу `http://localhost:7777/external?id=123&count=3`, видим, что получить ответ без
   исключения довольно проблемно

## Добавляем перехват исключений

1. В классе `App\Service\ExternalService` исправляем метод `getMultipleNames`
    ```php
    /**
     * @return array<int,string>
     */
    public function getMultipleNames(int $startId, int $count): array
    {
        $result = [];

        while ($count-- > 0) {
            try {
                $result[$startId] = $this->getName($startId);
            } catch (Exception) {
                // log exception
            }
            $startId++;
        }

        return $result;
    }
    ```
2. В классе `App\Controller\ExternalController` исправляем метод `requestExternalService`
    ```php
    public function requestExternalService(Request $request): Response
    {
        $externalEntityId = $request->query->get('id');
        $count = $request->query->get('count', 1);

        if (!is_numeric($externalEntityId) || !is_numeric($count)) {
            return new Response(null, Response::HTTP_BAD_REQUEST);
        }
        
        $result = $this->externalService->getMultipleNames((int)$externalEntityId, (int)$count);

        return new Response(implode(
            '; ',
            array_map(
                static fn (int $id, string $name): string => $id.': '.$name,
                array_keys($result),
                array_values($result),
            )
        ));
    }
    ```
3. Делаем несколько запросов по адресу `http://localhost:7777/external?id=123&count=10`, видим, что мы можем получить
   все успешно "обработанные" записи

## "Ломаем" внешний сервис

1. В классе `App\Service\ExternalService` устанавливаем значение константы `SUCCESSFUL_RATE` равным 0.
2. Делаем запрос `http://localhost:7777/external?id=123&count=10`, видим, что ответа нет, но и ошибки нет.

## Добавляем Graphite и интеграцию с ним

1. Добавляем сервис Graphite `docker-compose.yml`
    ```yaml
    graphite:
      image: graphiteapp/graphite-statsd
      container_name: 'graphite'
      restart: always
      ports:
        - '8000:80'
        - '2003:2003'
        - '2004:2004'
        - '2023:2023'
        - '2024:2024'
        - '8125:8125/udp'
        - '8126:8126'
    ```
2. Устанавливаем пакет для работы с Graphite командой `composer require slickdeals/statsd`
3. Выходим из контейнера командой `exit`
4. Запускаем контейнер с Graphite командой `docker-compose up graphite -d`
5. Делаем запрос `http://localhost:8000`, проверяем, что Graphite работает
6. Добавляем класс `App\Client\StatsdClient`
    ```php
    <?php
    
    namespace App\Client;
    
    use Domnikl\Statsd\Client;
    use Domnikl\Statsd\Connection\UdpSocket;
    
    class StatsdClient
    {
        private const DEFAULT_SAMPLE_RATE = 1.0;
    
        private Client $client;
    
        public function __construct(string $host, int $port, string $namespace)
        {
            $connection = new UdpSocket($host, $port);
            $this->client = new Client($connection, $namespace);
        }
    
        public function increment(string $key, ?float $sampleRate = null, ?array $tags = null): void
        {
            $this->client->increment($key, $sampleRate ?? self::DEFAULT_SAMPLE_RATE, $tags ?? []);
        }
    }
    ```
7. Добавляем интерфейс `App\Manager\SuccessMetricsManagerInterface`
    ```php
    <?php
    
    namespace App\Manager;
    
    interface SuccessMetricsManagerInterface
    {
        public function logSuccess(): void;
    
        public function logFail(): void;
    }
   ```
8. Добавляем класс `App\Manager\SuccessMetricsManager`
    ```php
    <?php
    
    namespace App\Manager;
    
    use App\Client\StatsdClient;
    
    class SuccessMetricsManager implements SuccessMetricsManagerInterface
    {
        private const SUCCESS_SUFFIX = 'success';
        private const FAIL_SUFFIX = 'fail';
        
        public function __construct(
            private readonly StatsdClient $statsdClient,
            private readonly string $metricName,
        ) {
        }
        
        public function logSuccess(): void
        {
            $this->log(self::SUCCESS_SUFFIX);
        }
        
        public function logFail(): void
        {
            $this->log(self::FAIL_SUFFIX);
        }
        
        private function log(string $suffix): void
        {
            $this->statsdClient->increment("$this->metricName.$suffix");
        }
    }
    ```
9. Исправляем класс `App\Service\ExternalService`
    ```php
    <?php
    
    namespace App\Service;
    
    use App\Manager\SuccessMetricsManagerInterface;
    use Exception;
    
    class ExternalService
    {
        private const SUCCESSFUL_RATE = 50;
        
        public function __construct(
            private readonly SuccessMetricsManagerInterface $successMetricsManager
        ) {
        }
    
        /**
         * @throws Exception
         */
        public function getName(int $externalEntityId): string
        {
            $isSuccessful = random_int(0, 99) < self::SUCCESSFUL_RATE;
    
            if (!$isSuccessful) {
                throw new Exception('Cannot request name for entity '. $externalEntityId);
            }
    
            return 'External Entity '.$externalEntityId;
        }
    
        /**
         * @return array<int,string>
         */
        public function getMultipleNames(int $startId, int $count): array
        {
            $result = [];
    
            while ($count-- > 0) {
                try {
                    $result[$startId] = $this->getName($startId);
                    $this->successMetricsManager->logSuccess();
                } catch (Exception) {
                    // log exception
                    $this->successMetricsManager->logFail();
                }
                $startId++;
            }
    
            return $result;
        }
    }
    ```
10. Добавляем настройки клиента и менеджера в файл `config/services.yaml`
    ```yaml
    App\Client\StatsdClient:
        arguments: 
            - graphite
            - 8125
            - my_app
    
    App\Manager\SuccessMetricsManager:
        arguments:
            $metricName: external_request
    ```
11. Делаем запрос `http://localhost:7777/external?id=123&count=10`, видим "обработанные" записи
12. Проверяем, что в Graphite появились метрики `stats_counts.my_app.external_request.success` и
    `stats_counts.my_app.external_request.success`

## Добавляем Grafana и строим в ней график

1. Добавляем сервис Grafana `docker-compose.yml`
    ```yaml
    grafana:
      image: grafana/grafana
      container_name: 'grafana'
      restart: always
      ports:
        - 3000:3000
    ```
2. Запускаем контейнер с Grafana командой `docker-compose up grafana -d` 
3. Делаем запрос http://localhost:3000
4. Авторизуемся с помощью логина / пароля `admin` / `admin`
5. Добавляем источник данных Graphite с URL `http://graphite:80`
6. Добавляем дашборд с графиком метрик `stats_counts.my_app.external_request.success` и
   `stats_counts.my_app.external_request.success`
7. Делаем несколько запросов `http://localhost:7777/external?id=123&count=10`, чтобы получить данные для графиков

## Добавляем алерт

1. Добавляем новый дашборд со следующими запросами:
   1. отключённый `stats_counts.my_app.external_request.success`
   2. отключённый `stats_counts.my_app.external_request.fail`
   3. отключённый `sumSeries(#A, #B)`
   4. `divideSeries(#B, #C)`
2. Делаем несколько запросов `http://localhost:7777/external?id=123&count=10`
3. В классе `App\Service\ExternalService` устанавливаем значение константы `SUCCESSFUL_RATE` равным 0.
4. Делаем ещё несколько запросов `http://localhost:7777/external?id=123&count=10`, видим на графике увеличение метрики
5. Добавляем алерт с условием `last() of D is above 0.7` с параметрами `evaulate every 1m for 1m` и сохраняем его в
   новую директорию.
6. Делаем в течение двух минут ещё запросы `http://localhost:7777/external?id=123&count=10`, видим, что алерт переходит
   сначала в состояние pending, затем alerting
7. В классе `App\Service\ExternalService` устанавливаем значение константы `SUCCESSFUL_RATE` равным 50.
8. Делаем в течение двух минут ещё запросы `http://localhost:7777/external?id=123&count=10`, видим, что алерт переходит
   в состояние normal
