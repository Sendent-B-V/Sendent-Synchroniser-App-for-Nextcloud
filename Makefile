# This file is licensed under the Affero General Public License version 3 or
# later. See the COPYING file.
# @author Bernhard Posselt <dev@bernhard-posselt.com>
# @copyright Bernhard Posselt 2016

# Generic Makefile for building and packaging a Nextcloud app which uses yarn and
# Composer.
#
# Dependencies:
# * make
# * which
# * curl: used if phpunit and composer are not installed to fetch them from the web
# * tar: for building the archive
# * yarn: for building and testing everything JS
#
# If no composer.json is in the app root directory, the Composer step
# will be skipped. The same goes for the package.json which can be located in
# the app root or the js/ directory.
#
# The yarn command by launches the yarn build script:
#
#    yarn build
#
# The yarn test command launches the yarn test script:
#
#    yarn test
#
# The idea behind this is to be completely testing and build tool agnostic. All
# build tools and additional package managers should be installed locally in
# your project, since this won't pollute people's global namespace.
#
# The following yarn scripts in your package.json install and update the bower
# and yarn dependencies and use gulp as build system (notice how everything is
# run from the node_modules folder):
#
#    "scripts": {
#        "test": "node node_modules/gulp-cli/bin/gulp.js karma",
#        "prebuild": "yarn install && node_modules/bower/bin/bower install && node_modules/bower/bin/bower update",
#        "build": "node node_modules/gulp-cli/bin/gulp.js"
#    },

app_name=$(notdir $(CURDIR))
build_tools_directory=$(CURDIR)/build/tools
source_build_directory=$(CURDIR)/build/artifacts/source
source_package_name=$(source_build_directory)/$(app_name)
appstore_build_directory=$(CURDIR)/build/artifacts/appstore
appstore_package_name=$(appstore_build_directory)/$(app_name)
yarn=$(shell which yarn 2> /dev/null)
composer=$(shell which composer 2> /dev/null)

all: build

# Fetches the PHP and JS dependencies and compiles the JS. If no composer.json
# is present, the composer step is skipped, if no package.json or js/package.json
# is present, the yarn step is skipped
.PHONY: build
build:
ifneq (,$(wildcard $(CURDIR)/composer.json))
	make composer
endif
ifneq (,$(wildcard $(CURDIR)/package.json))
	make yarn
endif
ifneq (,$(wildcard $(CURDIR)/js/package.json))
	make yarn
endif

# Installs and updates the composer dependencies. If composer is not installed
# a copy is fetched from the web
.PHONY: composer
composer:
ifeq (, $(composer))
	@echo "No composer command available, downloading a copy from the web"
	mkdir -p $(build_tools_directory)
	curl -sS https://getcomposer.org/installer | php
	mv composer.phar $(build_tools_directory)
	php $(build_tools_directory)/composer.phar install --prefer-dist
	php $(build_tools_directory)/composer.phar update --prefer-dist
else
	composer install --prefer-dist
	composer update --prefer-dist
endif

# Installs yarn dependencies
.PHONY: yarn
yarn:
ifeq (,$(wildcard $(CURDIR)/package.json))
	cd js && $(yarn) build
else
	yarn build
endif

# Removes the appstore build
.PHONY: clean
clean:
	rm -rf ./build

# Same as clean but also removes dependencies installed by composer, bower and
# yarn
.PHONY: distclean
distclean: clean
	rm -rf vendor
	rm -rf node_modules
	rm -rf js/vendor
	rm -rf js/node_modules

# Builds the source and appstore package
.PHONY: dist
dist:
	make source
	make appstore

# Builds the source package
.PHONY: source
source:
	rm -rf $(source_build_directory)
	mkdir -p $(source_build_directory)
	tar cvzf $(source_package_name).tar.gz \
	--exclude-vcs \
	--exclude="../$(app_name)/build" \
	--exclude="../$(app_name)/js/node_modules" \
	--exclude="../$(app_name)/node_modules" \
	--exclude="../$(app_name)/*.log" \
	--exclude="../$(app_name)/js/*.log" \
    ../$(app_name)

# Builds the source package for the app store, ignores php and js tests
.PHONY: appstore
appstore:
	rm -rf $(appstore_build_directory)
	mkdir -p $(appstore_build_directory)
	 tar cvzf $(appstore_package_name).tar.gz \
	--exclude-vcs \
	--exclude="../$(app_name)/build" \
	--exclude="../$(app_name)/tests" \
	--exclude="../$(app_name)/Makefile" \
 	--exclude="../$(app_name)/*.log" \
	--exclude="../$(app_name)/phpunit*xml" \
	--exclude="../$(app_name)/composer.*" \
	--exclude="../$(app_name)/node_modules" \
	--exclude="../$(app_name)/js/node_modules" \
	--exclude="../$(app_name)/js/tests" \
	--exclude="../$(app_name)/js/test" \
	--exclude="../$(app_name)/js/*.log" \
	--exclude="../$(app_name)/js/package.json" \
	--exclude="../$(app_name)/js/bower.json" \
	--exclude="../$(app_name)/js/karma.*" \
	--exclude="../$(app_name)/js/protractor.*" \
	--exclude="../$(app_name)/package.json" \
	--exclude="../$(app_name)/bower.json" \
	--exclude="../$(app_name)/karma.*" \
	--exclude="../$(app_name)/protractor\.*" \
	--exclude="../$(app_name)/.*" \
	--exclude="../$(app_name)/js/.*" \
	--exclude="../$(app_name)/webpack.config.js" \
	--exclude="../$(app_name)/stylelint.config.js" \
	--exclude="../$(app_name)/CHANGELOG.md" \
	--exclude="../$(app_name)/README.md" \
	--exclude="../$(app_name)/package-lock.json" \
	--exclude="../$(app_name)/LICENSES" \
	../$(app_name)

.PHONY: test
test: composer
	$(CURDIR)/vendor/phpunit/phpunit/phpunit -c phpunit.xml
	$(CURDIR)/vendor/phpunit/phpunit/phpunit -c phpunit.integration.xml
