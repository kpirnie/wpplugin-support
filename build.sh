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

# pick up the local minifier if it's been installed, otherwise let npx grab it
if [ -x "${ROOT}/node_modules/.bin/esbuild" ]; then
    ESBUILD="${ROOT}/node_modules/.bin/esbuild"
else
    ESBUILD="npx --yes esbuild"
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
    -not -path './vendor/*' \
    -not -path './node_modules/*' \
    -exec cp --parents {} "${DIST}/" \; )

# composer.json ships too, the github updater needs it
cp "${ROOT}/composer.json" "${DIST}/composer.json"

# minify the stylesheets
echo "# Working on Stylesheets"
for _css in "${SRC}"/assets/css/*.css; do
    [ -e "${_css}" ] || continue
    _name="$(basename "${_css}" .css)"
    ${ESBUILD} "${_css}" --minify --outfile="${DIST}/assets/css/${_name}.min.css" --log-level=warning
done

# minify the javascript
echo "# Working on JS"
for _js in "${SRC}"/assets/js/*.js; do
    [ -e "${_js}" ] || continue
    _name="$(basename "${_js}" .js)"
    ${ESBUILD} "${_js}" --minify --target=es2017 --outfile="${DIST}/assets/js/${_name}.min.js" --log-level=warning
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
mkdir -p "${DIST}/vendor"
cp -R "${ROOT}/vendor/." "${DIST}/vendor/"

# copy up to the production path if we've been told to
if [ "${SHOULD_COPY}" = "true" ]; then
    echo "# Copying to Production"
    mkdir -p "${PROD_PATH}"
    cp -R "${DIST}/." "${PROD_PATH}/"
fi

echo "# Done"