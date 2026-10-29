# version of the library, taken from the git tags
VERSION = $(shell git describe --tags --abbrev=0)

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
	$(RM) Docs/depage-docu/ Docs/html/ vendor/

doc:
	cd Docs ; git clone https://github.com/depage/depage-docu.git depage-docu || true
	( cat Docs/Doxyfile ; echo "PROJECT_NUMBER=$(VERSION)" ) | doxygen -
