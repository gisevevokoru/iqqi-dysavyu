<?php declare(strict_types=1);
use EzzeSiftuz\ProductsV1\Model\CategoryGroup;
use EzzeSiftuz\ProductsV1\Model\Delivery;
use EzzeSiftuz\ProductsV1\Model\MonetaryAmount;
use EzzeSiftuz\ProductsV1\Model\Pricing;
use EzzeSiftuz\ProductsV1\Model\ProductDescription;
use EzzeSiftuz\ProductsV1\Model\ProductVariation;
use EzzeSiftuz\ProductsV1\ObjectSerializer;
use Kepawni\Limerick\Hexastore;
use Predis\Client;
use XmlConversionFunctions as util;

require_once __DIR__ . '/../vendor/autoload.php';
//
$config = util::readConfigFromCommandLine($argv);
$inputFile = $config->inputFile;
$skusByEan = util::loadSkuMap($config->mysql, $config->schema, $config->{'db-user'}, $config->{'db-pass'});
$redisOptions = ['parameters' => ['database' => $config->{'redis-db-index'}, 'password' => $config->{'redis-secret'}]];
[$redisHost, $redisPort] = explode(':', $config->redis . ':6379');
$redis = new Client(sprintf("tcp://%s:%d", $redisHost, $redisPort), $redisOptions);
$hexastore = new Hexastore($redis, $config->hexastore);
//
$categoryGroupNamesByEditorialNodeCode = [
    "Badematten" => "Badematten",
    "20_4_2_Bademäntel" => "Hausmäntel",
    "Geschirr-Sets" => "Geschirr-Sets",
    "Karaffen" => "Karaffen",
    "Schüsseln" => "Schüsseln",
    "Servierplatten" => "Servierplatten",
    "Speiseteller" => "Speiseteller",
    "31_6_Handtücher (Packung)" => "Handtücher",
    "31_28_Handtuch-Sets" => "Handtuch-Sets",
];
$mediaAssetTypesByImageElementNames = [
    'MasterImage' => 'IMAGE',
    'Image' => 'IMAGE',
    'ColorVariantImage' => 'COLOR_VARIANT',
    'EnergyEfficiencyLabelImage' => 'ENERGY_EFFICIENCY_LABEL',
];
$productVariations = [];
$i = 0;
foreach (util::iterateProductsFromFile($inputFile) as $styleNode) {
    [$classOfGoods, $editorialNodeCode] = util::readCategory($styleNode);
    [$categoryGroupKey] = $hexastore->find(
        null,
        HexastorePredicate::BEARS_CATEGORY_GROUP_NAME,
        $categoryGroupNamesByEditorialNodeCode[$editorialNodeCode]
    )->current();
    /** @var CategoryGroup $categoryGroup */
    $categoryGroup = unserialize($redis->get($categoryGroupKey));
    $styleAttributes = [];
    $category = null;
    $productName = null;
    $description = null;
    $bulletPoints = null;
    $productLine = null;
    foreach (util::iterateAttributes($styleNode) as $name => $value) {
        if ($name === 'Produkt-Name') {
            $productName = $value;
        } elseif ($name === 'Produkttyp') {
            $category = $value;
        } elseif ($name === 'Besondere Merkmale') {
            $description = $value;
        } elseif ($name === 'Serie') {
            $productLine = $value;
        } elseif (substr($name, 0, 14) === 'Selling Point ') {
            $bulletPoints[] = $value;
        }
        $styleAttributes = util::copyAttributeIfSupported($name, $value, $styleAttributes, $categoryGroup);
    }
    foreach (util::iterateItems($styleNode) as $itemNode) {
        $itemAttributes = util::convertAttributes($itemNode, $categoryGroup);
        $images = util::convertImages($itemNode, $mediaAssetTypesByImageElementNames);
        foreach (util::iterateSkus($itemNode) as $skuNode) {
            $skuAttributes = util::convertAttributes($skuNode, $categoryGroup);
            $productDescription = (new ProductDescription())
                ->setAttributes(array_values($skuAttributes + $itemAttributes + $styleAttributes))
                ->setBrand('Lashuma')
                ->setBulletPoints($bulletPoints)
                // ->setBundle(false)
                ->setCategory($category)
                ->setDescription($description)
                // ->setDisposal(false)
                // ->setFscCertified(false)
                ->setManufacturer('Lashuma')
                // ->setMultiPack(false)
                // ->setProductionDate(new DateTimeImmutable())
                ->setProductLine($productLine)// ->setProductUrl('http://example.com/')
            ;
            $pricing = (new Pricing())
                ->setStandardPrice(
                    (new MonetaryAmount())
                        ->setAmount(util::readTextFromChild($skuNode, 'SellingPrice'))
                        ->setCurrency('EUR')
                )
                ->setVat('FULL');
            $ean = util::readTextFromChild($skuNode, 'EAN');
            echo 'processing #', ++$i . ': ', $ean, PHP_EOL;
            $productVariations[] = (new ProductVariation())
                ->setDelivery((new Delivery())->setType(Delivery::TYPE_PARCEL)->setDeliveryTime(2))
                ->setEan($ean)
                // ->setGtin($productVariation->getEan())
                // ->setIsbn('')
                // ->setLogistics(new \EzzeSiftuz\ProductsV1\Model\Logistics())
                // ->setMaxOrderQuantity(9999)
                ->setMediaAssets(array_values($images))
                // ->setMoin()
                // ->setMpn()
                // ->setOfferingStartDate(new Da)
                ->setPricing($pricing)
                ->setProductDescription($productDescription)
                ->setProductName(util::readTextFromChild($styleNode, 'StyleNo'))
                // ->setPzn()
                // ->setReleaseDate()
                ->setSku($skusByEan[$ean] ?? $ean)// ->setUpc()
            ;
        }
    }
}
echo util::simplifyPrettyPrintedProductVariants(
    json_encode(
        array_map([ObjectSerializer::class, 'sanitizeForSerialization'], $productVariations),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    )
);
