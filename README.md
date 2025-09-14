# Cacheer Monitor (Draft)

Dashboard e telemetria para o CacheerPHP — pacote separado e opcional.

## Instalação (no seu novo repositório)

composer.json:

```
{
  "require": {
    "silviooosilva/cacheer-php": "^4.7 || ^5.0",
    "cacheerphp/monitor": "dev-main"
  }
}
```

Estrutura deste pacote (draft):
- Reporter JSONL para registrar eventos
- Wrapper `InstrumentedCacheer` para instrumentar sem alterar o core
- CLI `cacheer-monitor` para subir um servidor local e exibir o painel

## Uso

1) Configure seu Cacheer normalmente:

```php
use Silviooosilva\CacheerPhp\Cacheer;

$cacheer = new Cacheer([
  'cacheDir' => __DIR__ . '/cache',
]);
$cacheer->setDriver()->useFileDriver();
```

2) Envolva com o wrapper instrumentado e use nas operações:

```php
use Cacheer\Monitor\InstrumentedCacheer;
use Cacheer\Monitor\Reporter\JsonlReporter;

$monitor = new JsonlReporter(); // opcional: caminho customizado
$instrumented = InstrumentedCacheer::wrap($cacheer, $monitor);

$instrumented->putCache('user:1', ['name' => 'Alice']);
$instrumented->getCache('user:1');
```

3) Inicie o painel:

```sh
vendor/bin/cacheer-monitor serve --port=9966
```

Abra http://127.0.0.1:9966

Env var opcional para o arquivo de eventos: `CACHEER_MONITOR_EVENTS=/caminho/para/events.jsonl`.

## Notas de Integração

- Este draft evita acoplamento com o core usando um wrapper. Em 5.0.0, pode-se adicionar uma Telemetry API (no-op por padrão) para emitir eventos direto dos drivers/serviços mantendo opt-in.
- O Reporter escreve em JSONL sob lock, com rotação simples. O servidor lê e agrega métricas (hits/misses, puts, flushes, top keys, etc.).
- Por enquanto, o wrapper não altera o encadeamento de métodos de configuração. Recomendado configurar o Cacheer primeiro e só então envolvê-lo com o `InstrumentedCacheer`.

## Roadmap

- Proxy de configuração para manter chaining (ex.: `setDriver()->useRedisDriver()` retornando o wrapper).
- Coleta de tamanhos por driver (mais precisa) e latências por operação.
- Filtros por namespace/driver, timeline e exportação.
- Autenticação quando exposto fora de `127.0.0.1`.

