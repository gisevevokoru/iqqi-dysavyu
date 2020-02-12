<?php declare(strict_types=1);
use EzzeSiftuz\ProductsV1\Api\CategoriesApi;
use EzzeSiftuz\ProductsV1\Configuration;
use EzzeSiftuz\ProductsV1\Model\CategoryGroups;
use GuzzleHttp\Client as HttpClient;
use Kepawni\Limerick\Hexastore;
use Predis\Client;
use RetrievalFunctions as util;

require_once __DIR__ . '/../vendor/autoload.php';
//
$config = util::readConfigFromCommandLine($argv);
$apiToken = util::requestNewApiToken($config->market, $config->username, $config->phrase);
$apiConfig = Configuration::getDefaultConfiguration()->setHost(sprintf("https://%s", $config->market));
$redisOptions = ['parameters' => ['database' => $config->{'redis-db-index'}, 'password' => $config->{'redis-secret'}]];
[$redisHost, $redisPort] = explode(':', $config->redis . ':6379');
$redis = new Client(sprintf("tcp://%s:%d", $redisHost, $redisPort), $redisOptions);
$hexastore = new Hexastore($redis, $config->hexastore);
//
$client = new HttpClient(['headers' => ['Authorization' => 'Bearer ' . $apiToken]]);
$categoriesApi = new CategoriesApi($client, $apiConfig);
$getCategoryGroups = function (?int $page) use ($categoriesApi) {
    return $categoriesApi->getCategoryGroups($page);
};
/** @var CategoryGroups $categoryGroups */
foreach (util::iteratePages($getCategoryGroups) as $categoryGroups) {
    foreach ($categoryGroups->getCategoryGroups() as $categoryGroup) {
        $serializedCategoryGroup = serialize($categoryGroup);
        $catGrpKey = 'cat-grp:' . sha1($serializedCategoryGroup);
        $redis->set($catGrpKey, $serializedCategoryGroup);
        $hexastore->store($catGrpKey, HexastorePredicate::BEARS_CATEGORY_GROUP_NAME, $categoryGroup->getCategoryGroup());
        $attDefHashesByName = [];
        foreach ($categoryGroup->getAttributes() as $attributeDefinition) {
            $serializedAttributeDefinition = serialize($attributeDefinition);
            $attDefKey = 'att-def:' . sha1($serializedAttributeDefinition);
            $redis->set($attDefKey, $serializedAttributeDefinition);
            $attDefHashesByName[$attributeDefinition->getName()] = $attDefKey;
            $hexastore->store($catGrpKey, HexastorePredicate::DEFINES_ATTRIBUTE, $attDefKey);
            $hexastore->store($attDefKey, HexastorePredicate::BEARS_ATTRIBUTE_NAME, $attributeDefinition->getName());
            $hexastore->store($attDefKey, HexastorePredicate::BELONGS_TO_ATTRIBUTE_GROUP, $attributeDefinition->getAttributeGroup());
            $hexastore->store($attDefKey, HexastorePredicate::HAS_ATTRIBUTE_TYPE, $attributeDefinition->getType());
            if ($attributeDefinition->getUnit()) {
                $hexastore->store($attDefKey, HexastorePredicate::AMOUNTS_ARE_GIVEN_IN, $attributeDefinition->getUnit());
            }
            if ($attributeDefinition->getUnitDisplayName()) {
                $hexastore->store(
                    $attDefKey,
                    HexastorePredicate::AMOUNTS_ARE_DISPLAYED_WITH_UNIT_NAME,
                    $attributeDefinition->getUnitDisplayName()
                );
            }
            $hexastore->store(
                $attDefKey,
                HexastorePredicate::ALLOWS_MULTIPLE_VALUES,
                $attributeDefinition->getMultiValue() ? 'true' : 'false'
            );
            $hexastore->store($attDefKey, HexastorePredicate::HAS_RELEVANCE, $attributeDefinition->getRelevance());
            foreach ($attributeDefinition->getAllowedValues() ?: [] as $value) {
                $hexastore->store($attDefKey, HexastorePredicate::ALLOWS_VALUE, $value);
            }
        }
        foreach ($categoryGroup->getCategories() as $categoryName) {
            $hexastore->store($catGrpKey, HexastorePredicate::CONTAINS_CATEGORY, $categoryName);
        }
        foreach ($categoryGroup->getVariationThemes() as $variationTheme) {
            if ($attDefHashesByName[$variationTheme] ?? null) {
                $hexastore->store(
                    $attDefHashesByName[$variationTheme],
                    HexastorePredicate::CREATES_VARIATION_WITHIN_CATEGORY_GROUP,
                    $catGrpKey
                );
            }
        }
    }
}
