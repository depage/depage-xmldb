# run the phpunit test suite (needs a mysql server configured in Tests/PhpUnit.xml)
test: vendor/bin/phpunit
	vendor/bin/phpunit --configuration Tests/PhpUnit.xml --no-coverage Tests/

# PHP version compatibility checks
compat: vendor/bin/phpcs
	vendor/bin/phpcs

# code style, check only (use `vendor/bin/php-cs-fixer fix` to apply)
cs: vendor/bin/php-cs-fixer
	vendor/bin/php-cs-fixer check --diff

vendor/bin/phpunit:
	composer install

vendor/bin/phpcs vendor/bin/php-cs-fixer: vendor/bin/phpunit

clean:
	rm -rf Tests/Report Tests/.phpunit*  vendor/
