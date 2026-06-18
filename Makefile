APP := http://hub.emeq.test:8092

.DEFAULT_GOAL := help
.PHONY: help up down restart urls fresh logs shell test ps tunnel

help: ## Toon deze hulp
	@grep -E '^[a-z-]+:.*##' $(MAKEFILE_LIST) | sort | awk 'BEGIN{FS=":.*## "}{printf "  \033[36m%-10s\033[0m %s\n", $$1, $$2}'

up: ## Start de hele stack (app + worker + vite + db + redis) + toon URLs
	docker compose up -d
	@$(MAKE) --no-print-directory urls

down: ## Stop de stack (volumes blijven; data behouden)
	docker compose down

restart: down up ## Herstart de stack

fresh: ## Verse, geseede DB in de app-container (wist data)
	docker compose exec -T app php artisan migrate:fresh --seed

logs: ## Tail app + worker logs
	docker compose logs -f app worker

shell: ## Open een shell in de app-container
	docker compose exec app sh

test: ## Draai de testsuite in de container
	docker compose exec -T app php artisan test --compact

ps: ## Toon container-status
	docker compose ps

tunnel: ## Start de stabiele Cloudflare named-tunnel (hub-dev.emeq.nl -> :8092)
	cloudflared tunnel run emeq-hub-dev

urls: ## Toon de UI-URLs
	@printf '\n'
	@printf '  \033[1m%-24s\033[0m %s\n' 'Wat' 'URL'
	@printf '  %-24s %s\n' '------------------------' '------------------------------------------'
	@printf '  %-24s \033[32m%s\033[0m\n' 'Public UI (React)'    '$(APP)/partners'
	@printf '  %-24s \033[32m%s\033[0m\n' 'Admin (auto-login)'   '$(APP)/admin/quick-login'
	@printf '  %-24s %s\n'                'Admin (echte login)'  '$(APP)/admin'
	@printf '  %-24s %s\n'                'Health / smoke'       '$(APP)/up'
	@printf '  %-24s %s\n'                'Vite HMR'             'http://localhost:5173'
	@printf '  %-24s %s\n'                'pgAdmin (DB-UI)'      'http://localhost:8091'
	@printf '\n  \033[2mApp boot duurt ~enkele seconden na up.\033[0m\n\n'
