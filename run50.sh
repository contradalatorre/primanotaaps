set -e
mkdir /work
cd /app
tar cf - --exclude=./vendor --exclude=./.git --exclude=./node_modules . | tar xf - -C /work
cd /work
cp .env.example .env
composer install --no-interaction --prefer-dist -q
touch /work/PRONTO
sleep 86400
