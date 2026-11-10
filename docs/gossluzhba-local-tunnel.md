# Прокси Госслужбы через локальную машину

На локальном компьютере маршрут к Госслужбе должен обходить WireGuard. Проверьте `ip route get 109.207.8.29` и отдельно проверьте реальный выход из контейнера: Docker Desktop использует собственную сетевую среду.

В локальном `.env` задайте `GOSSLUZHBA_SSH_PRIVATE_KEY_PATH`, `GOSSLUZHBA_SSH_KNOWN_HOSTS_PATH` и, при необходимости, `PROD_SSH_TARGET`. Ключи не добавляются в Git.

Запуск локальных сервисов:

```sh
docker compose -f docker-compose.yml -f docker-compose.gossluzhba-tunnel.yml --profile gossluzhba-local up -d --build gossluzhba-local-proxy gossluzhba-reverse-tunnel
```

На сервере после проверки туннеля задайте `GOSSLUZHBA_EXTERNAL_RUNNER=true` и поднимите `app` и `gossluzhba-runner` с этим compose-файлом. Runner использует готовый образ приложения, выполняет сбор раз в неделю и запускает публикацию только после успешного завершения сбора. До проверки работы туннеля не включайте расписание.
