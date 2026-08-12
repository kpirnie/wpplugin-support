#!/usr/bin/env bash
#
# KP Support - build script
#
# Compiles everything from source into distribute: php, minified assets,
# the generated pot file, and the composer vendor tree.

set -euo pipefail

# where we are, and where things live
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SRC="${ROOT}/source"
DIST="${ROOT}/distribute"
SLUG="kp-support"

# pull the production copy settings out of package.json
SHOULD_COPY="$(node -p "require('${ROOT}/package.json').production.shouldcopy")"
PROD_PATH="$(node -p "require('${ROOT}/package.json').production.path")"

# pick up the local minifiers if they've been installed, otherwise let npx grab them
if [ -x "${ROOT}/node_modules/.bin/terser" ]; then
    TERSER="${ROOT}/node_modules/.bin/terser"
    CLEANCSS="${ROOT}/node_modules/.bin/cleancss"
else
    TERSER="npx --yes terser"
    CLEANCSS="npx --yes clean-css-cli"
fi

# start clean, we never want stale files shipping
echo "# Cleaning Up Distribution"
rm -rf "${DIST}"
mkdir -p "${DIST}/assets/css" "${DIST}/assets/js" "${DIST}/languages"

# install the production dependencies at the root
echo "# Working on Composer"
( cd "${ROOT}" && composer install --no-dev --optimize-autoloader --quiet )

# copy the php, the templates, and the supporting files
echo "# Working on Templates"
( cd "${SRC}" && find . -type f \
    \( -name '*.php' -o -name 'readme.txt' -o -name 'LICENSE' \) \
    -not -path './assets/*' \
    -exec cp --parents {} "${DIST}/" \; )

# composer.json ships too, the github updater needs it
cp "${ROOT}/composer.json" "${DIST}/composer.json"

# minify the stylesheets
echo "# Working on Stylesheets"
for _css in "${SRC}"/assets/css/*.css; do
    [ -e "${_css}" ] || continue
    _name="$(basename "${_css}" .css)"
    ${CLEANCSS} -O2 -o "${DIST}/assets/css/${_name}.min.css" "${_css}"
done

# minify the javascript
echo "# Working on JS"
for _js in "${SRC}"/assets/js/*.js; do
    [ -e "${_js}" ] || continue
    _name="$(basename "${_js}" .js)"
    ${TERSER} "${_js}" --compress --mangle -o "${DIST}/assets/js/${_name}.min.js"
done

# generate the translation template off the source tree
echo "# Working on Languages"
wp i18n make-pot "${SRC}" "${DIST}/languages/${SLUG}.pot" \
    --slug="${SLUG}" \
    --domain="${SLUG}" \
    --exclude="assets,vendor,node_modules" \
    --allow-root

# and drop the vendor tree in
echo "# Working on Vendor"
cp -R "${ROOT}/vendor" "${DIST}/vendor"

# copy up to the production path if we've been told to
if [ "${SHOULD_COPY}" = "true" ]; then
    echo "# Copying to Production"
    mkdir -p "${PROD_PATH}"
    cp -R "${DIST}/." "${PROD_PATH}/"
fi

echo "# Done"