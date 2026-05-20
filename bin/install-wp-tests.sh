#!/usr/bin/env bash
# Installs WordPress core + the wp-phpunit test framework into a temp dir.
# Adapted from `wp scaffold plugin-tests`.
#
# Usage:
#   bin/install-wp-tests.sh <db-name> <db-user> <db-pass> [db-host] [wp-version] [skip-db-create]

set -euo pipefail

if [ $# -lt 3 ]; then
	echo "Usage: $0 <db-name> <db-user> <db-pass> [db-host] [wp-version] [skip-db-create]"
	exit 1
fi

DB_NAME="$1"
DB_USER="$2"
DB_PASS="$3"
DB_HOST="${4:-localhost}"
WP_VERSION="${5:-latest}"
SKIP_DB_CREATE="${6:-false}"

TMPDIR="${TMPDIR:-/tmp}"
TMPDIR="$(echo "$TMPDIR" | sed -e 's:/$::')"

WP_TESTS_DIR="${WP_TESTS_DIR:-$TMPDIR/wordpress-tests-lib}"
WP_CORE_DIR="${WP_CORE_DIR:-$TMPDIR/wordpress}"

download() {
	if command -v curl >/dev/null 2>&1; then
		curl -fsSL -o "$2" "$1"
	elif command -v wget >/dev/null 2>&1; then
		wget -nv -O "$2" "$1"
	else
		echo "Neither curl nor wget is installed." >&2
		exit 1
	fi
}

# Resolve the actual archive URL for the requested version.
if [[ "$WP_VERSION" =~ ^[0-9]+\.[0-9]+(\.[0-9]+)?$ ]]; then
	# Major.minor: hit the JSON API to find the latest patch.
	if [[ "$WP_VERSION" =~ ^[0-9]+\.[0-9]+$ ]]; then
		ARCHIVE_URL="https://wordpress.org/wordpress-${WP_VERSION}.tar.gz"
	else
		ARCHIVE_URL="https://wordpress.org/wordpress-${WP_VERSION}.tar.gz"
	fi
	WP_TESTS_TAG="tags/${WP_VERSION}"
elif [ "$WP_VERSION" = "nightly" ] || [ "$WP_VERSION" = "trunk" ]; then
	ARCHIVE_URL="https://wordpress.org/nightly-builds/wordpress-latest.zip"
	WP_TESTS_TAG="trunk"
else
	# "latest"
	ARCHIVE_URL="https://wordpress.org/latest.tar.gz"
	WP_TESTS_TAG="trunk"
fi

install_wp() {
	if [ -d "$WP_CORE_DIR" ]; then
		echo "WP core already at $WP_CORE_DIR — skipping download."
		return
	fi
	mkdir -p "$WP_CORE_DIR"
	download "$ARCHIVE_URL" "$TMPDIR/wordpress.tar.gz"
	tar --strip-components=1 -zxmf "$TMPDIR/wordpress.tar.gz" -C "$WP_CORE_DIR"
	download "https://raw.githubusercontent.com/markoheijnen/wp-mysqli/master/db.php" "$WP_CORE_DIR/wp-content/db.php" || true
}

install_test_suite() {
	if [ -d "$WP_TESTS_DIR" ]; then
		echo "Test suite already at $WP_TESTS_DIR — skipping checkout."
	else
		mkdir -p "$WP_TESTS_DIR"
		svn co --quiet "https://develop.svn.wordpress.org/${WP_TESTS_TAG}/tests/phpunit/includes/" "$WP_TESTS_DIR/includes"
		svn co --quiet "https://develop.svn.wordpress.org/${WP_TESTS_TAG}/tests/phpunit/data/" "$WP_TESTS_DIR/data"
	fi

	if [ ! -f "$WP_TESTS_DIR/wp-tests-config.php" ]; then
		download "https://develop.svn.wordpress.org/${WP_TESTS_TAG}/wp-tests-config-sample.php" "$WP_TESTS_DIR/wp-tests-config.php"
		# Portable sed (works on macOS and Linux).
		sed -i.bak \
			-e "s:dirname( __FILE__ ) . '/src/':'$WP_CORE_DIR/':" \
			-e "s/youremptytestdbnamehere/$DB_NAME/" \
			-e "s/yourusernamehere/$DB_USER/" \
			-e "s/yourpasswordherewp/$DB_PASS/" \
			-e "s|localhost|${DB_HOST}|" \
			"$WP_TESTS_DIR/wp-tests-config.php"
		rm -f "$WP_TESTS_DIR/wp-tests-config.php.bak"
	fi
}

install_db() {
	if [ "$SKIP_DB_CREATE" = "true" ]; then
		return
	fi
	mysqladmin create "$DB_NAME" --user="$DB_USER" --password="$DB_PASS" --host="$DB_HOST" --protocol=tcp || true
}

install_wp
install_test_suite
install_db

echo "WP core:       $WP_CORE_DIR"
echo "WP tests lib:  $WP_TESTS_DIR"
