APP := http://hub.emeq.test:8092
TUNNEL := emeq-hub-dev
TUNNEL_LOG := storage/logs/cloudflared.log

.DEFAULT_GOAL := help
.PHONY: help up down restart urls fresh seed-books logs logs-clean shell test ps tunnel tunnel-up tunnel-stop

help: ## Toon deze hulp
	@grep -E '^[a-z-]+:.*##' $(MAKEFILE_LIST) | sort | awk 'BEGIN{FS=":.*## "}{printf "  \033[36m%-10s\033[0m %s\n", $$1, $$2}'

up: ## Start de hele stack (app + worker + vite + db + redis) + verse logs/tunnel + toon URLs
	docker compose up -d
	@$(MAKE) --no-print-directory logs-clean
	@$(MAKE) --no-print-directory tunnel-up
	@$(MAKE) --no-print-directory urls

down: ## Stop de stack + tunnel (volumes blijven; data behouden)
	@$(MAKE) --no-print-directory tunnel-stop
	docker compose down

restart: down up ## Herstart de stack (+ verse tunnel)

fresh: ## Verse, geseede DB
	docker compose exec -T app php artisan migrate:fresh --seed

seed-exact: ## Vul Exact gegevens
	docker compose exec -T app php artisan db:seed --class=ExactDevSettingsSeeder

seed-books: ## (Her)vul het NL-grootboek (boekhouding) zonder data te wissen
	docker compose exec -T app php artisan db:seed --class=BooksChartSeeder

seed-relations: ## Vul Relaties
	docker compose exec -T app php artisan db:seed --class=BooksRelationsSeeder

seed-invoices: ## Vul Facturen
	docker compose exec -T app php artisan db:seed --class=BooksInvoiceSeeder

seed-bills: ## Vul Inkoop
	docker compose exec -T app php artisan db:seed --class=BooksBillSeeder

seed-payments: ## Vul Betalingen
	docker compose exec -T app php artisan db:seed --class=BooksPaymentSeeder

logs: ## Tail app + worker logs
	docker compose logs -f app worker

logs-clean: ## Truncate alle lokale logs in storage/logs (gitignored)
	@for f in storage/logs/*.log; do [ -f "$$f" ] && : > "$$f"; done; echo "  🧹 storage/logs/*.log getruncate"

shell: ## Open een shell in de app-container
	docker compose exec app sh

test: ## Draai de testsuite in de container
	docker compose exec -T app php artisan test --compact

ps: ## Toon container-status
	docker compose ps

tunnel-up: ## (Her)start de Cloudflare-tunnel op de ACHTERgrond (kill eerst alle bestaande)
	@pkill -f "cloudflared tunnel run $(TUNNEL)" 2>/dev/null || true
	@sleep 1
	@nohup cloudflared tunnel run $(TUNNEL) > $(TUNNEL_LOG) 2>&1 & \
		echo "  ☁️  tunnel '$(TUNNEL)' (her)start op de achtergrond -> $(TUNNEL_LOG)"

tunnel-stop: ## Stop alle Cloudflare-tunnels van deze stack
	@pkill -f "cloudflared tunnel run $(TUNNEL)" 2>/dev/null || true

tunnel: ## Start de tunnel op de VOORgrond (live logs; kill eerst alle bestaande)
	@pkill -f "cloudflared tunnel run $(TUNNEL)" 2>/dev/null || true
	@sleep 1
	cloudflared tunnel run $(TUNNEL)

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
