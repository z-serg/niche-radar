# Niche Radar — быстрое управление локальным стеком (Docker Compose).
# Полный список целей: make help

# Порт из .env (формат 127.0.0.1:8180 или просто 8180); по умолчанию — как в compose.yaml.
APP_PORT := $(shell sed -n 's/^APP_PORT=//p' .env 2>/dev/null | tail -1)
ifeq ($(APP_PORT),)
APP_PORT := 127.0.0.1:8080
endif

ifneq (,$(findstring :,$(APP_PORT)))
URL := http://$(APP_PORT)
else
URL := http://localhost:$(APP_PORT)
endif

.DEFAULT_GOAL := up
.PHONY: up down stop restart open logs ps test help

up: ## Собрать и запустить стек, дождаться готовности и открыть сайт в браузере
	docker compose up -d --build
	@echo "[make] ожидание готовности приложения ($(URL))..."
	@i=0; until curl -fsS $(URL)/api/v1/health >/dev/null 2>&1; do \
		i=$$((i+1)); \
		if [ $$i -ge 60 ]; then \
			echo "[make] приложение не ответило за 2 минуты; откройте $(URL) позже"; \
			exit 1; \
		fi; \
		sleep 2; \
	done
	@echo "[make] приложение готово: $(URL)"
	$(MAKE) --no-print-directory open

down: ## Остановить и убрать контейнеры (тома с данными сохраняются)
	docker compose down

stop: ## Синоним down
	$(MAKE) --no-print-directory down

restart: ## Перезапустить стек с пересборкой
	$(MAKE) --no-print-directory down
	$(MAKE) --no-print-directory up

open: ## Открыть сайт в браузере
	@if command -v xdg-open >/dev/null 2>&1; then \
		xdg-open $(URL) 2>/dev/null || echo "[make] не удалось открыть браузер: $(URL)"; \
	elif command -v open >/dev/null 2>&1; then \
		open $(URL) 2>/dev/null || echo "[make] не удалось открыть браузер: $(URL)"; \
	else \
		echo "[make] откройте в браузере: $(URL)"; \
	fi

logs: ## Логи всех сервисов (по Ctrl+C выход, контейнеры продолжают работать)
	docker compose logs -f

ps: ## Состояние сервисов
	docker compose ps

test: ## Тесты в изолированной БД (./bin/test)
	./bin/test

help: ## Этот список
	@echo "Niche Radar — цели Makefile:"
	@grep -E '^[a-zA-Z_-]+:.*## ' $(MAKEFILE_LIST) | awk -F'## ' '{sub(/:$$/, "", $$1); printf "  make %-8s %s\n", $$1, $$2}'
