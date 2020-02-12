# Migration tools

## Retrieve the category definitions from the API

Those definitions specify which attributes are allowed and required for products within a certain category. This is a
huge amount of data, so we rely on Redis to cache it for some time, until we run into inconclusive errors feeding the
suspicion that we might need an update.

    php bin/retrieve-catgories-into-redis.php OPTIONS

    Options (required):
      -r  --redis HOSTPORT   Host (and port if not 6379) to Redis server, e. g. "example.com:6379"
      -m  --market HOSTPORT  Host (and port if not 443) to Market API server, e. g. "example.com"
      -u  --username NAME    Market user
      -p  --phrase PASSWORD  Market password

    Options (optional):
      -i  --redis-db-index NUM     Redis database index, default 0
      -s  --redis-secret PASSWORD  Redis secret key
      -h  --hexastore NAME         Redis key of the sorted set holding the hexastore, default "hexastore"

## Convert old product XML to new JSON

The conversion requires knowledge of the available categories and their allowed ar required attributes. That's why we
need Redis here, where this information is stored. (See previous section on how to initialize or update this store.)

    php bin/product-xml-to-json.php OPTIONS XML_IN_FILE [ > JSON_OUT_FILE ]

    Options (required):
      -r  --redis HOSTPORT    Host (and port if not 6379) to Redis server, e. g. "example.com:6379"
      -m  --mysql HOSTPORT    Host (and port if not 3306) to MySQL server, e. g. "example.com:3306"
      -d  --schema NAME       MySQL schema (database) name
      -u  --db-user NAME      MySQL user
      -p  --db-pass PASSWORD  MySQL password

    Options (optional):
      -i  --redis-db-index NUM     Redis database index, default 0
      -s  --redis-secret PASSWORD  Redis secret key
      -h  --hexastore NAME         Redis key of the sorted set holding the hexastore, default "hexastore"
