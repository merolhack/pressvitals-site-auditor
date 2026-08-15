#!/usr/bin/env bash
# Install the WordPress test suite + a test database.
# Usage: bin/install-wp-tests.sh <db-name> <db-user> <db-pass> [db-host] [wp-version] [skip-database-creation]
# Canonical script from the wp-cli scaffold; kept verbatim so contributors get the standard workflow.

if [ $# -lt 3 ]; then
	echo "usage: $0 <db-name> <db-user> <db-pass> [db-host] [wp-version] [skip-database-creation]"
	exit 1
fi

DB_NAME=$1
DB_USER=$2
DB_PASS=$3
DB_HOST=${4-localhost}
WP_VERSION=${5-latest}
SKIP_DB_CREATE=${6-false}

TMPDIR=${TMPDIR-/tmp}
TMPDIR=$(echo "$TMPDIR" | sed -e "s/\/$//")
WP_TESTS_DIR=${WP_TESTS_DIR-$TMPDIR/wordpress-tests-lib}
WP_CORE_DIR=${WP_CORE_DIR-$TMPDIR/wordpress}

download() {
	if [ "$(which curl)" ]; then
		curl -sSf "$1" > "$2" || return 1
	elif [ "$(which wget)" ]; then
		wget -nv -O "$2" "$1" || return 1
	else
		echo "Error: neither curl nor wget is available."
		exit 1
	fi
}

if [[ $WP_VERSION =~ ^[0-9]+\.[0-9]+\-(beta|RC)[0-9]+$ ]]; then
	WP_BRANCH=${WP_VERSION%\-*}
	WP_TESTS_TAG="branches/$WP_BRANCH"
elif [[ $WP_VERSION =~ ^[0-9]+\.[0-9]+$ ]]; then
	WP_TESTS_TAG="branches/$WP_VERSION"
elif [[ $WP_VERSION =~ [0-9]+\.[0-9]+\.[0-9]+ ]]; then
	if [[ $WP_VERSION =~ [0-9]+\.[0-9]+\.[0]+ ]]; then
		WP_TESTS_TAG="tags/${WP_VERSION%??}"
	else
		WP_TESTS_TAG="tags/$WP_VERSION"
	fi
elif [[ $WP_VERSION == 'nightly' || $WP_VERSION == 'trunk' ]]; then
	WP_TESTS_TAG="trunk"
else
	download http://api.wordpress.org/core/version-check/1.7/ /tmp/wp-latest.json || true
	LATEST_VERSION=$(grep -o '"version":"[^"]*' /tmp/wp-latest.json 2>/dev/null | sed 's/"version":"//' | head -1)
	if [[ -z "$LATEST_VERSION" ]]; then
		WP_TESTS_TAG="trunk"
	else
		WP_TESTS_TAG="tags/$LATEST_VERSION"
	fi
fi
set -ex

install_wp() {
	if [ -d "$WP_CORE_DIR" ]; then
		return;
	fi
	mkdir -p "$WP_CORE_DIR"
	if [[ $WP_VERSION == 'nightly' || $WP_VERSION == 'trunk' ]]; then
		mkdir -p "$TMPDIR/wordpress-nightly"
		download https://wordpress.org/nightly-builds/wordpress-latest.zip "$TMPDIR/wordpress-nightly/wordpress-nightly.zip"
		if [ "$(which unzip)" ]; then
			unzip -q "$TMPDIR/wordpress-nightly/wordpress-nightly.zip" -d "$TMPDIR/wordpress-nightly/"
		elif [ "$(which python3)" ]; then
			python3 -c "import zipfile; zipfile.ZipFile('$TMPDIR/wordpress-nightly/wordpress-nightly.zip').extractall('$TMPDIR/wordpress-nightly/')"
		elif [ "$(which php)" ]; then
			php -r "\$zip = new ZipArchive; if (\$zip->open('$TMPDIR/wordpress-nightly/wordpress-nightly.zip') === TRUE) { \$zip->extractTo('$TMPDIR/wordpress-nightly/'); \$zip->close(); }"
		fi
		mv "$TMPDIR/wordpress-nightly/wordpress"/* "$WP_CORE_DIR"
	else
		local downloaded=false
		if [ "$WP_VERSION" == 'latest' ]; then
			download https://wordpress.org/latest.tar.gz "$TMPDIR/wordpress.tar.gz" && downloaded=true
		elif [[ $WP_VERSION =~ [0-9]+\.[0-9]+ ]]; then
			if [[ $WP_VERSION =~ [0-9]+\.[0-9]+\.[0]+ ]]; then
				local ARCHIVE_NAME="wordpress-${WP_VERSION%??}"
			else
				local ARCHIVE_NAME="wordpress-$WP_VERSION"
			fi
			if download https://wordpress.org/${ARCHIVE_NAME}.tar.gz "$TMPDIR/wordpress.tar.gz"; then
				downloaded=true
			fi
		fi

		if [ "$downloaded" = true ]; then
			tar --strip-components=1 -zxmf "$TMPDIR/wordpress.tar.gz" -C "$WP_CORE_DIR"
		else
			echo "Specific release tarball for $WP_VERSION not found (pre-release/unreleased). Falling back to nightly/pre-release build..."
			mkdir -p "$TMPDIR/wordpress-nightly"
			download https://wordpress.org/nightly-builds/wordpress-latest.zip "$TMPDIR/wordpress-nightly/wordpress-nightly.zip"
			if [ "$(which unzip)" ]; then
				unzip -q "$TMPDIR/wordpress-nightly/wordpress-nightly.zip" -d "$TMPDIR/wordpress-nightly/"
			elif [ "$(which python3)" ]; then
				python3 -c "import zipfile; zipfile.ZipFile('$TMPDIR/wordpress-nightly/wordpress-nightly.zip').extractall('$TMPDIR/wordpress-nightly/')"
			elif [ "$(which php)" ]; then
				php -r "\$zip = new ZipArchive; if (\$zip->open('$TMPDIR/wordpress-nightly/wordpress-nightly.zip') === TRUE) { \$zip->extractTo('$TMPDIR/wordpress-nightly/'); \$zip->close(); }"
			fi
			mv "$TMPDIR/wordpress-nightly/wordpress"/* "$WP_CORE_DIR"
		fi
	fi
	download https://raw.githubusercontent.com/markoheijnen/wp-mysqli/master/db.php "$WP_CORE_DIR/wp-content/db.php" || true
}

install_test_suite() {
	if [[ $(uname -s) == 'Darwin' ]]; then
		local ioption='-i.bak'
	else
		local ioption='-i'
	fi

	if [ ! -d "$WP_TESTS_DIR" ]; then
		mkdir -p "$WP_TESTS_DIR"
		rm -rf "$WP_TESTS_DIR/{includes,data}"
		svn export --quiet --ignore-externals https://develop.svn.wordpress.org/${WP_TESTS_TAG}/tests/phpunit/includes/ "$WP_TESTS_DIR/includes"
		svn export --quiet --ignore-externals https://develop.svn.wordpress.org/${WP_TESTS_TAG}/tests/phpunit/data/ "$WP_TESTS_DIR/data"
	fi

	if [ ! -f wp-tests-config.php ]; then
		download https://develop.svn.wordpress.org/${WP_TESTS_TAG}/wp-tests-config-sample.php "$WP_TESTS_DIR"/wp-tests-config.php
		WP_CORE_DIR=$(echo "$WP_CORE_DIR" | sed "s:/\+$::")
		sed $ioption "s:dirname( __FILE__ ) . '/src/':'$WP_CORE_DIR/':" "$WP_TESTS_DIR"/wp-tests-config.php
		sed $ioption "s:__DIR__ . '/src/':'$WP_CORE_DIR/':" "$WP_TESTS_DIR"/wp-tests-config.php
		sed $ioption "s/youremptytestdbnamehere/$DB_NAME/" "$WP_TESTS_DIR"/wp-tests-config.php
		sed $ioption "s/yourusernamehere/$DB_USER/" "$WP_TESTS_DIR"/wp-tests-config.php
		sed $ioption "s/yourpasswordhere/$DB_PASS/" "$WP_TESTS_DIR"/wp-tests-config.php
		sed $ioption "s|localhost|${DB_HOST}|" "$WP_TESTS_DIR"/wp-tests-config.php
	fi
}

install_db() {
	if [ "${SKIP_DB_CREATE}" = "true" ]; then
		return 0
	fi

	local PARTS=(${DB_HOST//\:/ })
	local DB_HOSTNAME=${PARTS[0]};
	local DB_SOCK_OR_PORT=${PARTS[1]};
	local EXTRA=""

	if [ -n "$DB_HOSTNAME" ]; then
		if [ "$(echo "$DB_SOCK_OR_PORT" | grep -e '^[0-9]\{1,\}$')" ]; then
			EXTRA=" --host=$DB_HOSTNAME --port=$DB_SOCK_OR_PORT --protocol=tcp"
		elif [ -n "$DB_SOCK_OR_PORT" ]; then
			EXTRA=" --socket=$DB_SOCK_OR_PORT"
		elif [ -n "$DB_HOSTNAME" ]; then
			EXTRA=" --host=$DB_HOSTNAME --protocol=tcp"
		fi
	fi

	mysqladmin create "$DB_NAME" --user="$DB_USER" --password="$DB_PASS"$EXTRA
}

install_wp
install_test_suite
install_db
