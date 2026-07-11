APP := http://hub.emeq.test:8092
TUNNEL := emeq-hub-dev
TUNNEL_LOG := storage/logs/cloudflared.log

# Prod (draait op de server, niet lokaal) — zie docs/deployment.md.
PROD := docker compose -f docker-compose.prod.yml --env-file .env.prod
BACKUP_DIR := backups

# Doel voor `prod-rsync` (draait wél lokaal). Overschrijf per host:
#   make prod-rsync PROD_HOST=hub-vps
PROD_HOST ?= naschool
PROD_PATH ?= /home/ubuntu/emeq-hub

.DEFAULT_GOAL := help
.PHONY: help up down restart urls fresh seed-books logs logs-clean shell test ps tunnel tunnel-up tunnel-stop \
	prod-deploy prod-up prod-rsync prod-logs prod-ps prod-shell prod-backup prod-down

help: ## Toon deze hulp
	@grep -E '^[a-z-]+:.*##' $(MAKEFILE_LIST) | sort | awk 'BEGIN{FS=":.*## "}{printf "  \033[36m%-12s\033[0m %s\n", $$1, $$2}'

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

## ---------------------------------------------------------------------------
## Prod — draai deze targets op de server, in de repo-checkout naast .env.prod.
## ---------------------------------------------------------------------------

prod-deploy: ## [server] Release vanuit git: pull → prod-up
	git pull --ff-only
	@$(MAKE) --no-print-directory prod-up

prod-up: ## [server] Release zonder git (na `prod-rsync`): backup → build → migrate → restart
	@test -f .env.prod || { echo "  ✖ .env.prod ontbreekt (kopieer .env.prod.example)"; exit 1; }
	@$(MAKE) --no-print-directory prod-backup
	$(PROD) up -d --build
	$(PROD) exec -T app sh -c "php artisan migrate --force && php artisan optimize"
	@# Worker-mode houdt code in geheugen: horizon/scheduler draaien de oude image
	@# tot een expliciete restart. Altijd samen met app herstarten.
	$(PROD) restart app horizon scheduler
	@$(PROD) exec -T app curl -fsS http://localhost/up && echo "\n  ✅ deploy ok"

prod-rsync: ## [lokaal] Push de working tree naar de server (PROD_HOST/PROD_PATH), zonder git
	rsync -az --delete \
		--exclude '.git' --exclude 'vendor' --exclude 'node_modules' \
		--exclude '.env' --exclude '.env.*' --exclude 'backups' \
		--exclude 'packages' --exclude 'public/build' --exclude 'public/hot' \
		--exclude 'storage/logs/*' --exclude 'storage/framework/cache/data/*' \
		./ $(PROD_HOST):$(PROD_PATH)/
	@echo "  📤 gesynct naar $(PROD_HOST):$(PROD_PATH) — draai daar 'make prod-up'"

prod-backup: ## [server] pg_dump naar backups/ (draait automatisch vóór prod-deploy)
	@mkdir -p $(BACKUP_DIR)
	@$(PROD) exec -T db pg_dump -U "$$(grep -E '^DB_USERNAME=' .env.prod | cut -d= -f2)" \
		-d "$$(grep -E '^DB_DATABASE=' .env.prod | cut -d= -f2)" \
		| gzip > "$(BACKUP_DIR)/emeq-hub-$$(date +%Y%m%d-%H%M%S).sql.gz"
	@ls -1t $(BACKUP_DIR)/*.sql.gz | head -1 | xargs -I{} echo "  💾 backup: {}"

prod-logs: ## [server] Tail app + horizon + tunnel
	$(PROD) logs -f --tail=100 app horizon cloudflared

prod-ps: ## [server] Container-status (health)
	$(PROD) ps

prod-shell: ## [server] Shell in de app-container
	$(PROD) exec app sh

prod-down: ## [server] Stop de prod-stack (volumes/data blijven)
	$(PROD) down

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
