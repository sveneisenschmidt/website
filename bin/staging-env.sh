# Sourced by the Makefile before a target talks to staging. Fills what the
# environment leaves empty: the staging password from the macOS keychain
# (service kirby-staging, stored once with make kirby-staging-login) and the
# assistant key from the local env.php. A launch with PUBLIC=1 gets no
# staging password, so the deploy removes .htpasswd.

if [ "${PUBLIC:-0}" != 1 ] && [ -z "${STAGING_PASSWORD:-}" ] && command -v security >/dev/null 2>&1; then
  STAGING_USER=$(security find-generic-password -s kirby-staging 2>/dev/null | sed -n 's/^ *"acct"<blob>="\(.*\)"$/\1/p')
  STAGING_PASSWORD=$(security find-generic-password -s kirby-staging -w 2>/dev/null)
  export STAGING_USER STAGING_PASSWORD
fi

if [ -z "${ANTHROPIC_API_KEY:-}" ] && [ -f www/site/config/env.php ]; then
  ANTHROPIC_API_KEY=$(php -r 'echo (require "www/site/config/env.php")["assistantKey"] ?? "";')
  export ANTHROPIC_API_KEY
fi
