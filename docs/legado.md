# Módulos antigos da Centraliza: levantamento e porte

Os pacotes `caravel/module-centraliza-base` (1.0.17), `-notification` (1.0.13) e `-sales` (1.0.23) são substituídos por este módulo. O catálogo (`Centraliza_Notification`) já foi substituído pelos streams de conexão. O resto (mensagens de pedido, carrinho abandonado, link de recuperação, callback de entrega) é **transplantado**: o código dos antigos vem para cá com o mínimo de mudança, e só a base embaixo dele (configuração, ambiente, log) passa a ser a deste módulo.

Os contratos externos não mudam: tipos de evento, corpos, `idempotency_key`, URL do callback, IDs de ACL, rota do link e nomes de tabelas e colunas. Assim nada muda do lado da Centraliza, os links já enviados continuam valendo e o histórico fica nas mesmas tabelas, sem migração de dados.

## Fases

| Fase | Conteúdo | Situação |
|---|---|---|
| 1. Fundação | tabelas e colunas dos antigos declaradas de novo aqui; cliente da API e cota por chave (transplantados do Base); envio de entidades dentro da cota compartilhada; validação da chave no admin; formatação de telefone; trava de convivência; modo simulação para as mensagens | feita |
| 2. Pedido | `order.status_updated` e `order.created`, callback de entrega, limpeza de 90 dias, grid com "enviar agora" e reenvio | a fazer |
| 3. Carrinho | link de recuperação (rota, troca de carrinho no login, merge, marca no pedido), registro de atividade, detecção e envio de carrinho abandonado, grid | a fazer |
| 4. Corte | o import passa a copiar todas as configs antigas; indexers do catálogo antigo em "Update on Save"; deploy com os antigos desabilitados no `config.php`; conferência; `composer remove` dos três pacotes, com `giggsey/libphonenumber-for-php` no `composer.json` da loja | a fazer |

As fases 1 a 3 sobem com os antigos ainda ligados e não têm efeito enquanto eles estiverem ativos. O teste de verdade em HML é desligar os antigos lá.

## Mapa do transplante

| Antigo | Aqui |
|---|---|
| `Centraliza\Base\Api\*`, `Model\*`, `Exception\*`, `Logger\*`, `Block\*`, `Ui\*` | `Centraliza\Connector\Messaging\...`, mesmo caminho relativo |
| `Centraliza\Base\Controller\Adminhtml\Connection\Validate` (rota `centraliza_base`) | `Centraliza\Connector\Controller\Adminhtml\Connection\Validate` (rota `centraliza_connector`) |
| virtualTypes `CentralizaLogger`, `CentralizaSafeLogger`, `CentralizaDebugLogger`, `Centraliza*Client` | mesmos, com prefixo `CentralizaConnector` (nomes de virtualType são globais e os antigos ainda usam os seus) |
| `Centraliza\Sales\...` | fases 2 e 3, também em `Centraliza\Connector\Messaging\...`; controllers em `Controller\` |

O que mudou no código transplantado, e por quê:

| Classe | Mudança |
|---|---|
| `Messaging\Model\Config` | lê a conexão deste módulo, por website como nos antigos: chave e URL (`base_url_<ambiente>` + `/v1/`) do ambiente do website; website nulo é o website que reporta. `isEnabled(website)` = o website com "Report changes to Centraliza" (um por vez); `isReady()` é falso enquanto `Centraliza_Sales` estiver habilitado |
| `Messaging\Model\Auth\ConnectionStatus` | grava o estado da validação no escopo do website, por ambiente (`centraliza_connector/connection/*_<ambiente>`) |
| `Messaging\Model\Api\Client`, `ClientFactory` | a URL base é a do ambiente do website da requisição (`getApiBaseUrl($websiteId)`) |
| `Messaging\Model\Api\Client` | em modo simulação (`mode` = `dry_run`), toda escrita vai para `var/log/centraliza_connector_dry_run.jsonl` e não sai da loja; leitura (a validação da chave) sai normalmente |
| `Messaging\Model\Api\QuotaReservation` | piso "bulk" em `centraliza_connector/delivery/bulk_quota_floor`; "bulk" agora são os eventos de conexão |
| `Messaging\Block\...\AuthenticateButton` + `authenticate.js` | valida a chave do ambiente salvo; se o seletor de ambiente foi trocado e não salvo, pede para salvar antes; avisa enquanto os antigos estiverem ativos |

O código transplantado não foi adaptado ao phpstan nível 8 do resto do módulo. No nível 5 sobram avisos de classe gerada (factories) e método mágico do Magento, mais dois que já vinham dos antigos: um `catch` que não pode ocorrer em `Client` e comparações de `fetchOne()` com `false` em `RateLimit`. Ficaram como vieram.

## Tabelas mantidas

Declaradas de novo em `etc/db_schema.xml`, idênticas às dos antigos. Sem isso, desabilitar `Centraliza_Base` ou `Centraliza_Sales` faria o `setup:upgrade` apagá-las: a whitelist do declarative schema vem de todos os módulos registrados (`Diff::getWhiteListTables`), e a declaração só dos ativos.

| Tabela ou coluna | Origem | Conteúdo |
|---|---|---|
| `centraliza_rate_limit`, `centraliza_rate_limit_refusal` | Base | cota por chave (janela de 60 s), bloqueio por 429, pausa por 401/403 |
| `centraliza_notification` | Sales | fila e histórico das mensagens de pedido |
| `centraliza_sales_abandoned_cart` | Sales | seleção e envio de carrinhos abandonados |
| `centraliza_sales_cart_activity` | Sales | última visita do cliente ao carrinho ou checkout |
| `centraliza_sales_cart_link` | Sales | ids dos links de carrinho de cliente |
| `quote.centraliza_masked_id`, `sales_order.centraliza_masked_id` | Sales | link que abriu o carrinho; o trigger mview `trg_sales_order_after_update` lê a coluna de `sales_order` |

Conferido no banco local com o diff do declarative schema (sem executar nada): com os antigos ativos, nenhuma operação nessas tabelas; com os três desabilitados, só as 7 tabelas do catálogo antigo (`centraliza_catalog_*`) seriam apagadas, que é o esperado.

**Enquanto os antigos estiverem instalados, nenhuma dessas declarações pode mudar**: as duas precisam ficar iguais.

## Cota compartilhada

A Centraliza limita as requisições por minuto por chave, somando todas as rotas; os antigos mediram 60. As mensagens e os eventos de conexão usam a mesma chave e a mesma rota (`admin/events`), por isso reservam na mesma tabela (`centraliza_rate_limit`), com prioridades:

| Prioridade | Quem | Para quando restam |
|---|---|---|
| interactive | validação da chave no admin | 0 |
| transactional | mensagens de pedido | 10 |
| marketing | carrinho abandonado | 20 |
| bulk | eventos de conexão (`Infra\Http\SharedQuota`) | `bulk_quota_floor`, padrão 30 (de 21 até um a menos que o limite) |

O limite é configurável em `centraliza_connector/delivery/request_limit` (admin: Delivery > Request Limit Per Minute, padrão 60):

- **Um número** (22 ou mais): a janela de 60 s conta até ele. Os pisos das mensagens são absolutos: com 600, os eventos de conexão usam até 570 e as mensagens continuam com 30 reservadas. Um piso que não cabe no limite (salvo por CLI, ou limite reduzido depois) volta para 30, ou para um a menos que o limite.
- **0**: sem limite local. Nada é contado; só um 429 da Centraliza (bloqueio de até 60 s) ou uma chave recusada seguram as requisições.
- **Enquanto o `Centraliza_Base` estiver habilitado**, um valor acima de 60 vale como 60. O limitador antigo conta com 60 fixo na mesma linha, e um limite maior aqui deixaria os eventos de conexão gastarem o que o WhatsApp antigo espera. O valor salvo passa a valer sozinho quando os antigos forem desabilitados.

Com o padrão, os eventos de conexão ficam com no máximo ~30 por minuto. Quando a cota acaba, o consumer espera o fim da janela (até 60 s) e tenta de novo. Se ainda não houver requisição livre, ou se a chave estiver pausada, o evento volta para a tabela de retry sem gastar tentativa. Um 401 dos eventos pausa a chave inteira por 15 min, mensagens incluídas, porque elas também seriam recusadas. 403 e 404 dos eventos não pausam a rota: nos eventos eles podem ser channel ID errado, e pausar `admin/events` calaria as mensagens de pedido.

## Levantamento das funcionalidades

### Mensagens de status de pedido (`Centraliza_Sales`)

- Observer `PublishOrderStatusChange` em `sales_order_save_commit_after` grava uma linha em `centraliza_notification` quando o status muda para um da lista do website (`centraliza/order_notification/notified_statuses`, JSON). Só com `order_notification/enabled` e `Base::isReady(website)`.
- `order.created` só para pedido cujo carrinho veio do link (`sales_order.centraliza_masked_id` preenchido) e com `order_created_enabled`; `event_id` `order-<id>-created`.
- Cron `centraliza_notification_send` (todo minuto, grupo `centraliza_notification`) e `centraliza_notification_clean` (03:15, apaga após 90 dias).
- Status da linha: `pending`, `sending`, `retry`, `delivered`, `sent`, `not_sent`, `failed`, `unknown`.
- Regras de entrega: FIFO por pedido; reivindica (`pending → sending`) antes do HTTP; no máximo uma vez (`unknown` é final, nunca reenvia algo que talvez tenha saído); nova tentativa a cada 15 min; até 5 envios (1 a 10); desiste 24 h depois de `created_at`.
- Corpo: `type`, `idempotency_key` `order-<id>-<to_status>` (criado: `order-<id>-event-created`), `contact{name, phone (E.164 sem "+": customer_whatsapp, senão billing), email, marketing_opt_in: true}`, `data.order{id, number, status, status_label, total, currency, url}`, `data.cart{id, masked_id}` opcional. `order.created` acrescenta created_at, store/website, cupom, totais, itens, cliente, endereços, pagamento e entrega.
- `external_id` vem de `data.id` da resposta.
- Admin: grid `centraliza_notification_listing`, "enviar agora", reenvio com travas (máx. 500), exportação CSV bloqueada. ACL `Centraliza_Notification::notification_log`, `::notification_resend`, `::notification_send_now`.

### Callback de entrega

- `POST /rest/V1/centraliza/notification/sent` com `{id, sent}`, só para Integration com a ACL `Centraliza_Notification::delivery_callback` (declarada no `acl.xml` do Sales); aliases assíncronos desligados.
- Move uma linha `delivered` para `sent` ou `not_sent`, buscando por `external_id`.

### Carrinho abandonado

- Cron `centraliza_sales_abandoned_cart` (todo minuto, grupo default), relógio do banco.
- Só carrinho de cliente logado (visitante fica de fora por segurança). `abandon_minutes` 60, `send_limit_minutes` 1440, `max_notifications` 1.
- `centraliza_sales_cart_activity` é alimentada no predispatch de `checkout_cart_index` e `checkout_index_index` para cliente logado.
- Reenvio quando a assinatura do carrinho muda ou quando ele é revisitado depois de `sent_at`.
- `centraliza_sales_abandoned_cart`: único por (`quote_id`, `reminder_number`); status `pending`, `processing`, `success`, `retry`, `failed`; novas tentativas em 5, 10, 20, 40 e 80 min.
- Corpo: `cart.abandoned`, `idempotency_key` `cart-<quote_id>[-YmdHis]`, contact, `data.cart{id, masked_id, total, currency, items_count, items[{sku, name, quantity, price}]}`.
- Admin: grid `centraliza_sales_abandoned_cart_listing`; ACL `Centraliza_Sales::abandoned_cart`, `::abandoned_cart_send_now`.

### Link de recuperação de carrinho

- Rota de frontend `centraliza` (frontName): `/centraliza/cart_redirect/?cart_id=<32 alfanuméricos>`.
- Procura primeiro em `centraliza_sales_cart_link`, depois em `quote_id_mask`. HEAD (prévia do WhatsApp) redireciona sem alterar nada. 404 quando desligado.
- Visitante sem permissão vai para o login, com a sessão guardando `centraliza_cart_redirect_quote_id`. Cliente logado vai para o checkout; os demais, para o carrinho.
- Observers: `ReplaceCustomerCartOnLogin` (`load_customer_quote_before`), `CarryCartLinkOnMerge` (`sales_quote_merge_after`), `CopyCartOriginToOrder` (`sales_model_service_quote_submit_before`) + `fieldset.xml`.

### Base

- Cliente HTTP: URL fixa `https://api.centraliza.ai/v1/`, `Bearer csk_...`, timeouts 15 s / 5 s, sem retry de POST. A taxonomia de erro distingue "talvez enviado" de "nunca enviado".
- Cota: 60/min por chave com pisos por prioridade; 401 pausa a chave por 900 s, 403 pausa a rota.
- Botão de validar a chave: `GET admin/workspace`, grava `centraliza/connection/*`.
- Telefone: libphonenumber, região padrão BR.
- Log: `var/log/centraliza.log`.
- ACL `Centraliza_Base::menu`, `Centraliza_Base::centraliza`.

### Catálogo (`Centraliza_Notification`)

Produto, preço e estoque por mview (`centraliza_catalog_product`, `_inventory`, `_stock_links`). As rotas nunca existiram na Centraliza. Substituído pelos streams `products`, `prices` e `stock` deste módulo; desligado por config com `centraliza:legacy:import-config --disable-legacy-catalog`.

## Perguntas para o time da Centraliza

1. 60 requisições por minuto por chave, divididas com o WhatsApp, deixam os eventos de conexão com ~30 por minuto: uma carga inicial de ~3 mil produtos (~9 mil eventos entre produto, preço e estoque) leva horas. Qual é o limite real da chave? Dá para ter um limite maior ou uma cota separada para os eventos? O valor que eles informarem vai em `request_limit`.
2. `order.created` ainda é contrato proposto, não confirmado.
3. Os antigos mandam `marketing_opt_in: true` sempre, com um comentário de que isso não serve para produção (LGPD), e está assim em produção hoje.
