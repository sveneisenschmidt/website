# Sourced by the Makefile before a deploy. Fills the assistant key from the
# local env.php when the environment leaves it empty, so a deploy keeps the
# key on the server without typing it.

if [ -z "${ANTHROPIC_API_KEY:-}" ] && [ -f www/site/config/env.php ]; then
  ANTHROPIC_API_KEY=$(php -r 'echo (require "www/site/config/env.php")["assistantKey"] ?? "";')
  export ANTHROPIC_API_KEY
fi
