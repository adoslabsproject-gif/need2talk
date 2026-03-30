#!/bin/bash
# Populate emotions with icons and AI keywords (ENTERPRISE: PostgreSQL)

POSTGRES_CMD="docker exec need2talk_postgres psql -U need2talk -d need2talk -c"

# Emotion 2: Entusiasmo
$POSTGRES_CMD "UPDATE emotions SET icon_emoji = '🤩', ai_keywords = '[\"entusiasmo\", \"energia\", \"excited\", \"enthusiasm\"]'::jsonb WHERE id = 2;"

# Emotion 3: Amore
$POSTGRES_CMD "UPDATE emotions SET icon_emoji = '❤️', ai_keywords = '[\"amore\", \"affetto\", \"tenerezza\", \"love\", \"romantic\"]'::jsonb WHERE id = 3;"

# Emotion 4: Gratitudine
$POSTGRES_CMD "UPDATE emotions SET icon_emoji = '🙏', ai_keywords = '[\"gratitudine\", \"grazie\", \"grateful\", \"thankful\"]'::jsonb WHERE id = 4;"

# Emotion 5: Speranza
$POSTGRES_CMD "UPDATE emotions SET icon_emoji = '🌟', ai_keywords = '[\"speranza\", \"ottimismo\", \"hope\", \"optimism\", \"faith\"]'::jsonb WHERE id = 5;"

# Emotion 6: Tristezza
$POSTGRES_CMD "UPDATE emotions SET icon_emoji = '😢', ai_keywords = '[\"tristezza\", \"triste\", \"malinconia\", \"sad\", \"sorrow\"]'::jsonb WHERE id = 6;"

# Emotion 7: Rabbia
$POSTGRES_CMD "UPDATE emotions SET icon_emoji = '😠', ai_keywords = '[\"rabbia\", \"collera\", \"arrabbiato\", \"anger\", \"rage\", \"fury\"]'::jsonb WHERE id = 7;"

# Emotion 8: Ansia
$POSTGRES_CMD "UPDATE emotions SET icon_emoji = '😰', ai_keywords = '[\"ansia\", \"preoccupazione\", \"stress\", \"anxiety\", \"worry\"]'::jsonb WHERE id = 8;"

# Emotion 9: Paura
$POSTGRES_CMD "UPDATE emotions SET icon_emoji = '😨', ai_keywords = '[\"paura\", \"timore\", \"spavento\", \"fear\", \"scared\", \"afraid\"]'::jsonb WHERE id = 9;"

# Emotion 10: Solitudine
$POSTGRES_CMD "UPDATE emotions SET icon_emoji = '😔', ai_keywords = '[\"solitudine\", \"solo\", \"isolato\", \"lonely\", \"alone\"]'::jsonb WHERE id = 10;"

echo "✅ Emotions icons and keywords populated (PostgreSQL)!"
