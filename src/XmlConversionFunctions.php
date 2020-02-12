<?php declare(strict_types=1);
use deswerve\colin\CommandLineInterface;
use EzzeSiftuz\ProductsV1\Model\Attribute;
use EzzeSiftuz\ProductsV1\Model\CategoryGroup;
use EzzeSiftuz\ProductsV1\Model\MediaAsset;

class XmlConversionFunctions
{
    public static function convertAttributes(DOMElement $ownerNode, CategoryGroup $categoryGroup): array
    {
        $attributes = [];
        foreach (self::iterateAttributes($ownerNode) as $name => $value) {
            $attributes = self::copyAttributeIfSupported($name, $value, $attributes, $categoryGroup);
        }
        return $attributes;
    }

    public static function convertImages(DOMElement $itemNode, array $mediaAssetTypesByImageElementNames): array
    {
        $images = [];
        foreach (self::iterateImages($itemNode) as $fileName => $type) {
            $images[$fileName] = (new MediaAsset())
                ->setLocation($fileName)
                ->setType($mediaAssetTypesByImageElementNames[$type]);
        }
        return $images;
    }

    public static function copyAttributeIfSupported(string $name, string $value, array $attributes, CategoryGroup $categoryGroup): array
    {
        foreach ($categoryGroup->getAttributes() as $attributeDefinition) {
            if ($attributeDefinition->getName() === $name) {
                if (isset($attributes[$name])) {
                    $attributes[$name]->setValues(array_merge($attributes[$name]->getValues(), [$value]));
                } else {
                    $attributes[$name] = new Attribute();
                    $attributes[$name]->setName($name);
                    $attributes[$name]->setValues([$value]);
                }
            }
        }
        return $attributes;
    }

    public static function iterateAttributes(DOMElement $node): Generator
    {
        $xpath = new DOMXPath($node->ownerDocument);
        $xpath->registerNamespace('o', 'http://www.ottogroupb2b.com/ottopimpartner');
        foreach ($xpath->query('.//o:EditorialAttributeValue', $node) as $item) {
            yield $xpath->evaluate('string(o:Code)', $item) => $xpath->evaluate('string(o:Value)', $item);
        }
    }

    public static function iterateImages(DOMElement $itemNode): Generator
    {
        $xpath = new DOMXPath($itemNode->ownerDocument);
        $xpath->registerNamespace('o', 'http://www.ottogroupb2b.com/ottopimpartner');
        foreach ($xpath->query('o:Images/*/o:FileName', $itemNode) as $fileName) {
            yield $fileName->textContent => $fileName->parentNode->nodeName;
        }
    }

    public static function iterateItems(DOMElement $styleNode): DOMNodeList
    {
        $xpath = new DOMXPath($styleNode->ownerDocument);
        $xpath->registerNamespace('o', 'http://www.ottogroupb2b.com/ottopimpartner');
        return $xpath->query('o:Item', $styleNode);
    }

    public static function iterateProductsFromFile(string $inputFile): DOMNodeList
    {
        $document = new DOMDocument();
        $document->load($inputFile);
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('o', 'http://www.ottogroupb2b.com/ottopimpartner');
        return $xpath->query('/o:ottopartner/o:Styles/o:Style');
    }

    public static function iterateSkus(DOMElement $itemNode): DOMNodeList
    {
        $xpath = new DOMXPath($itemNode->ownerDocument);
        $xpath->registerNamespace('o', 'http://www.ottogroupb2b.com/ottopimpartner');
        return $xpath->query('o:SKU', $itemNode);
    }

    public static function loadSkuMap(string $hostPort, string $schema, string $user, string $password): array
    {
        $scmDb = new PDO(
            sprintf("mysql:host=%s;dbname=%s", str_replace(':', ';port=', $hostPort), $schema),
            $user,
            $password,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            ]
        );
        $scmDb->exec("set character set utf8");
        $query = <<<'EOD'
select
    group_concat(distinct if(ti.name = 'ASKU', pi.value, null)) ASKU,
    group_concat(distinct if(ti.name = 'GTIN', pi.value, null)) GTIN
from _11_1n__commodity__identification c2i
join _0n_1n__identification__identifier i2i on i2i.identification = c2i.identification
join property pi on pi.id = c2i.identification
join type ti on ti.id = i2i.identifier
where ti.name in ('ASKU', 'GTIN')
group by c2i.commodity
EOD;
        $skusByEan = [];
        foreach ($scmDb->query($query) as $row) {
            $skusByEan[$row->GTIN] = $row->ASKU;
        }
        return $skusByEan;
    }

    public static function readCategory(DOMElement $styleNode): array
    {
        $xpath = new DOMXPath($styleNode->ownerDocument);
        $xpath->registerNamespace('o', 'http://www.ottogroupb2b.com/ottopimpartner');
        return [
            $xpath->evaluate('string(o:ClassOfGoods)', $styleNode),
            $xpath->evaluate('string(o:EditorialLink/o:EditorialNodeCode)', $styleNode),
        ];
    }

    public static function readConfigFromCommandLine(array $argv): object
    {
        $colin = new CommandLineInterface($argv[0], ['OPTIONS XML_IN_FILES... [ > JSON_OUT_FILE ]']);
        $commandLine = $colin
            ->addOption('redis', 'Host (and port if not 6379) to Redis server, e. g. "example.com:6379"', ['HOSTPORT'], 'r')
            ->addOption('redis-db-index', 'Redis database index, default 0', ['NUM'], 'i', true, [0])
            ->addOption('redis-secret', 'Redis secret key', ['PASSWORD'], 's', true, [null])
            ->addOption('hexastore', 'Redis key of the sorted set holding the hexastore, default "hexastore"', ['NAME'], 'h', true, ['hexastore'])
            ->addOption('mysql', 'Host (and port if not 3306) to MySQL server, e. g. "example.com:3306"', ['HOSTPORT'], 'm')
            ->addOption('schema', 'MySQL schema (database) name', ['NAME'], 'd')
            ->addOption('db-user', 'MySQL user', ['NAME'], 'u')
            ->addOption('db-pass', 'MySQL password', ['PASSWORD'], 'p')
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
            if (empty($commandLine->params) || !is_file($commandLine->params[0])) {
                throw new RuntimeException('Missing or invalid input file');
            }
            [$config->inputFile] = $commandLine->params;
            return $config;
        } catch (RuntimeException $e) {
            echo $e->getMessage(), PHP_EOL, PHP_EOL, $colin;
            exit;
        }
    }

    public static function readTextFromChild(DOMElement $parentElement, string $childElementName): string
    {
        $xpath = new DOMXPath($parentElement->ownerDocument);
        $xpath->registerNamespace('o', 'http://www.ottogroupb2b.com/ottopimpartner');
        return $xpath->evaluate(sprintf('string(o:%s)', $childElementName), $parentElement);
    }

    public static function simplifyPrettyPrintedProductVariants(string $json): string
    {
        return preg_replace(
            [
                '<\\{\\s+("amount": "[^"]+",)\\s+("currency": "[^"]+")\\s+\\}>',
                '<(\\n {12})\\{\\s+("type": "[^"]+",)\\s+("location": "[^"]+")\\s+\\}>',
                '<(\\n {16})\\{\\s+("name": "[^"]+",)\\s+"values": \\[\\s+([^\\]]+?)\\s+\\]\\s+\\}>',
                '<\\n {24}("[^"]+"(?:,|]}))>',
            ],
            [
                '{\\1 \\2}',
                '\\1{\\2 \\3}',
                '\\1{\\2 "values": [\\3]}',
                ' \\1',
            ],
            $json
        );
    }
}
