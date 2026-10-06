#!/usr/bin/env bash
# Smoke test: run the installer against a real database, then check the main screens load.
# Usage: tests/smoke.sh http://127.0.0.1:8000  (needs DB_NAME, DB_USER, DB_PASS, DB_HOST)
set -euo pipefail
BASE="${1:-http://127.0.0.1:8000}"
JAR="$(mktemp)"
fail() { echo "FAIL: $*" >&2; exit 1; }
# Fetch a page fully before searching it (grep -q closing the pipe early would fail curl).
has() { local body; body=$(curl -sf "${@:2}") || return 1; grep -q -- "$1" <<<"$body"; }
token() { grep -o 'name="csrf" value="[a-f0-9]*"' | head -1 | sed 's/.*value="//; s/"//'; }

echo "1. Installer"
CSRF=$(curl -sf -c "$JAR" -b "$JAR" "$BASE/install.php" | token)
[ -n "$CSRF" ] || fail "no form token on install.php"
OUT=$(curl -sf -c "$JAR" -b "$JAR" "$BASE/install.php" \
  --data-urlencode "csrf=$CSRF" --data-urlencode "action=install" \
  --data-urlencode "db_host=${DB_HOST:-127.0.0.1}" --data-urlencode "db_name=$DB_NAME" \
  --data-urlencode "db_user=$DB_USER" --data-urlencode "db_pass=$DB_PASS" \
  --data-urlencode "kitchen=Test kitchen" --data-urlencode "name=Tester" \
  --data-urlencode "email=test@example.com" --data-urlencode "password=correct-horse" \
  --data-urlencode "demo=1")
echo "$OUT" | grep -q "Your recipe portal is ready" || { echo "$OUT" | grep -o 'class="alert bad">[^<]*' >&2; fail "installer did not finish"; }

echo "2. Kitchen screens"
has "What do you need?" "$BASE/" || fail "start screen"
has "Admin sign in" "$BASE/" || fail "admin sign-in link on the start screen"
has "Chicken katsu rice bowl" "$BASE/recipes.php" || fail "library does not list the demo recipe"
CODE=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$BASE/?brand=1")
case "$CODE" in "302 "*recipes.php?brand=1) ;; *) fail "old library link should redirect, got $CODE" ;; esac
has "What you need" "$BASE/recipe.php?id=1" || fail "recipe page"
has "data-cook" "$BASE/cook.php?id=1" || fail "step-by-step page"
has '"start_url"' "$BASE/manifest.php" || fail "manifest"
has "mustr-recipes-" "$BASE/sw.php" || fail "service worker"
has "Print label" "$BASE/labels.php" || fail "labels screen"
has 'href="/labels.php"' "$BASE/recipes.php" || fail "Labels tab on the recipes screen"
has 'href="/recipes.php"' "$BASE/labels.php" || fail "Recipes tab on the labels screen"
has "labels.php?item=" "$BASE/recipe.php?id=1" || fail "Print label button on the recipe"
CODE=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/recipe.php?id=999")
[ "$CODE" = "404" ] || fail "missing recipe should be 404, got $CODE"

echo "3. Admin sign-in"
CSRF=$(curl -sf -c "$JAR" -b "$JAR" "$BASE/admin/login.php" | token)
curl -sf -c "$JAR" -b "$JAR" -o /dev/null "$BASE/admin/login.php" \
  --data-urlencode "csrf=$CSRF" --data-urlencode "email=test@example.com" --data-urlencode "password=correct-horse"
for page in "" recipe-edit.php?id=1 labels.php labels.php?new=1 brands.php transfer.php update.php settings.php; do
  has 'class="side"' -b "$JAR" "$BASE/admin/$page" || fail "admin/$page"
done

rm -f "$JAR"
echo "All smoke tests passed."
