<?php declare(strict_types=1);
use deswerve\colin\CommandLineInterface;

class RetrievalFunctions
{
    /**
     * To iterate over the responses of paged requests until there are no more pages, like instead of for instance
     *     $result = $fooApi->getBarGroups('quux', true, null, 100);
     *     $result = $fooApi->getBarGroups('quux', true, 1, 100);
     *     $result = $fooApi->getBarGroups('quux', true, 2, 100);
     * you can use
     *     $callback = function(?int $page) use($fooApi) {
     *         return $fooApi->getBarGroups('quux', true, $page, 100);
     *     }
     *     foreach (iteratePages($callback) as $result)) {
     *         // ...
     *     }
     * instead. The response needs to provide the getLinks() method, whose results will be scanned for the one with
     * getRel() == 'next' which must respond to getHref() with a URI string featuring a page query parameter.
     *
     * @param callable $pageCallback
     * @param int|null $startAt
     *
     * @return Generator
     */
    public static function iteratePages(callable $pageCallback, ?int $startAt = null): Generator
    {
        do {
            $result = $pageCallback($startAt);
            $startAt = null;
            if (is_object($result) && method_exists($result, 'getLinks')) {
                foreach ($result->getLinks() as $link) {
                    if (is_object($link) && method_exists($link, 'getRel') && method_exists($link, 'getHref') && $link->getRel() === 'next') {
                        parse_str(parse_url($link->getHref(), PHP_URL_QUERY), $data);
                        $startAt = intval($data['page'] ?? null);
                    }
                }
            }
            yield $result;
        } while ($startAt);
    }

    public static function readConfigFromCommandLine(array $argv): stdClass
    {
        $colin = new CommandLineInterface($argv[0], ['OPTIONS']);
        $commandLine = $colin
            ->addOption('redis', 'Host (and port if not 6379) to Redis server, e. g. "example.com:6379"', ['HOSTPORT'], 'r')
            ->addOption('redis-db-index', 'Redis database index, default 0', ['NUM'], 'i', true, [0])
            ->addOption('redis-secret', 'Redis secret key', ['PASSWORD'], 's', true, [null])
            ->addOption('hexastore', 'Redis key of the sorted set holding the hexastore, default "hexastore"', ['NAME'], 'h', true, ['hexastore'])
            ->addOption('market', 'Host (and port if not 443) to Market API server, e. g. "example.com"', ['HOSTPORT'], 'm')
            ->addOption('username', 'Market user', ['NAME'], 'u')
            ->addOption('phrase', 'Market password', ['PASSWORD'], 'p')
            ->processCommandLine($argv);
        try {
            $options = (array)$commandLine->options;
            $config = (object)array_combine(
                array_keys($options),
                array_map(
                    function (string $key) use ($options) {
                        $value = $options[$key]->values[0] ?? null;
                        if ($key !== 'redis-secret' && is_null($value)) {
                            throw new RuntimeException('Missing option ' . $key);
                        }
                        return $value;
                    },
                    array_keys($options)
                )
            );
            if (!empty($commandLine->params)) {
                throw new RuntimeException('Unsupported extra parameters found');
            }
            return $config;
        } catch (RuntimeException $e) {
            echo $e->getMessage(), PHP_EOL, PHP_EOL, $colin;
            exit;
        }
    }

    public static function requestNewApiToken(string $hostPort, string $username, string $phrase): string
    {
        return json_decode(
            file_get_contents(
                sprintf('https://%s/v1/token', $hostPort),
                false,
                stream_context_create(
                    [
                        'http' => [
                            'method' => 'POST',
                            'header' => "Content-Type: application/x-www-form-urlencoded\r\nCache-Control: no-cache",
                            'content' => http_build_query(
                                [
                                    'username' => $username,
                                    'password' => $phrase,
                                    'grant_type' => 'password',
                                    'client_id' => 'token-otto-api',
                                ],
                                '',
                                "&",
                                PHP_QUERY_RFC3986
                            ),
                        ],
                        'ssl' => ['verify_host' => true, 'allow_self_signed' => true],
                    ]
                )
            ),
            false,
            2,
            JSON_THROW_ON_ERROR
        )->access_token;
    }
}
