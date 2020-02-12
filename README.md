# Migration tools

## Convert old product XML to new JSON

    php bin/product-xml-to-json.php OPTIONS XML_IN_FILES... [ > JSON_OUT_FILE ]

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
