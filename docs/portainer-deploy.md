# Deploy no Portainer

1. Crie uma stack usando este repositório.
2. Defina a variável `PUBLIC_HOST` com o IP ou domínio do servidor (ex: `172.16.21.110`).
3. Suba o stack com `docker-compose.yml`.

## Portas usadas

- App Novo SGA: `7001`
- Mercure: `7002`
- MySQL: `7003`

Todas estão dentro da faixa `7001-7010`.
