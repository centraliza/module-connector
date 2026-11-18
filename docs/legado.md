# Módulos antigos da Centraliza: levantamento e porte

Os pacotes `caravel/module-centraliza-base` (1.0.17), `-notification` (1.0.13) e `-sales` (1.0.23) são substituídos por este módulo. O catálogo (`Centraliza_Notification`) já foi substituído pelos streams de conexão. O resto (mensagens de pedido, carrinho abandonado, link de recuperação, callback de entrega) é **transplantado**: o código dos antigos vem para cá com o mínimo de mudança, e só a base embaixo dele (configuração, ambiente, log) passa a ser a deste módulo.

Os contratos externos não mudam: tipos de evento, corpos, `idempotency_key`, URL do callback, IDs de ACL, rota do link e nomes de tabelas e colunas. Assim nada muda do lado da Centraliza, os links já enviados continuam valendo e o histórico fica nas mesmas tabelas, sem migração de dados.

## Fases

| Fase | Conteúdo | Situação |
|---|---|---|
| 1. Fundação | tabelas e colunas dos antigos declaradas de novo aqui; cliente da API e cota por chave (transplantados do Base); envio de entidades dentro da cota compartilhada; validação da chave no admin; formatação de telefone; trava de convivência; modo simulação para as mensagens | feita |
| 2. Pedido | `order.status_updated` e `order.created`, callback de entrega, limpeza de 90 dias, grid com "enviar agora" e reenvio | feita |
| 3. Carrinho | link de recuperação (rota, troca de carrinho no login, merge, marca no pedido), registro de atividade, detecção e envio de carrinho abandonado, grid | feita |
| 4. Corte | o import passa a copiar todas as configs antigas; indexers do catálogo antigo em "Update on Save"; deploy com os antigos desabilitados no `config.php`; conferência; `composer remove` dos três pacotes, com `giggsey/libphonenumber-for-php` no `composer.json` da loja | a fazer |

As fases 1 a 3 sobem com os antigos ainda ligados e não têm efeito enquanto eles estiverem ativos. O teste de verdade em HML é desligar os antigos lá e rodar `centraliza:legacy:import-config`, que copia a chave e as configurações das mensagens.

## Mapa do transplante

| Antigo | Aqui |
|---|---|
| `Centraliza\Base\Api\*`, `Model\*`, `Exception\*`, `Logger\*`, `Block\*`, `Ui\*` | `Centraliza\Connector\Messaging\...`, mesmo caminho relativo |
| `Centraliza\Base\Controller\Adminhtml\Connection\Validate` (rota `centraliza_base`) | `Centraliza\Connector\Controller\Adminhtml\Connection\Validate` (rota `centraliza_connector`) |
| virtualTypes `CentralizaLogger`, `CentralizaSafeLogger`, `CentralizaDebugLogger`, `Centraliza*Client` | mesmos, com prefixo `CentralizaConnector` (nomes de virtualType são globais e os antigos ainda usam os seus) |
| `Centraliza\Sales\...` | `Centraliza\Connector\Messaging\...`, mesmo caminho relativo (`Api`, `Block`, `Cron`, `Model`, `Observer`, `Plugin`, `Service`, `Ui`); controllers em `Controller\Adminhtml\OrderNotification` (rota `centraliza_connector`) |
| grid `centraliza_notification_listing`, layout `centraliza_sales_ordernotification_index` | `centraliza_connector_notification_listing`, `centraliza_connector_ordernotification_index`: os arquivos de UI component de mesmo nome se mesclariam com os do antigo |
| `centraliza/order_notification/*`, `centraliza/abandoned_cart/*`, `centraliza/cart_redirect/enabled` | `centraliza_connector/order_notification/*`, `centraliza_connector/abandoned_cart/*`, `centraliza_connector/cart_redirect/enabled`, todos os campos editáveis no website |
| grid `centraliza_sales_abandoned_cart_listing`, layout `centraliza_sales_abandonedcart_index` | `centraliza_connector_abandoned_cart_listing`, `centraliza_connector_abandonedcart_index` |
| observers `centraliza_sales_*` (atividade, login, merge, marca no pedido), cron `centraliza_sales_abandoned_cart` | `centraliza_connector_*` com o mesmo papel |
| `Setup/Patch/Data/*` do Sales | não portados: convertem configurações e links de versões antigas do próprio Sales, e já rodaram onde ele está instalado |
| observer `centraliza_notification_publish_order_status_change`, crons `centraliza_notification_send`/`_clean`, plugin `centraliza_refuse_order_notification_export` | `centraliza_connector_order_notification`, `centraliza_connector_notification_send`/`_clean`, `centraliza_connector_refuse_notification_export`: o mesmo nome substituiria o do antigo enquanto ele estiver instalado |

O que mudou no código transplantado, e por quê:

| Classe | Mudança |
|---|---|
| `Messaging\Model\Config` | lê a conexão deste módulo, por website como nos antigos: chave e URL (`base_url_<ambiente>` + `/v1/`) do ambiente do website; website nulo é o website que reporta. `isEnabled(website)` = o website com "Report changes to Centraliza" (um por vez); `isReady()` é falso enquanto `Centraliza_Sales` estiver habilitado |
| `Messaging\Model\Auth\ConnectionStatus` | grava o estado da validação no escopo do website, por ambiente (`centraliza_connector/connection/*_<ambiente>`) |
| `Messaging\Model\Api\Client`, `ClientFactory` | a URL base é a do ambiente do website da requisição (`getApiBaseUrl($websiteId)`) |
| `Messaging\Model\Api\Client` | em modo simulação (`mode` = `dry_run`), toda escrita vai para `var/log/centraliza_connector_dry_run.jsonl` e não sai da loja; leitura (a validação da chave) sai normalmente |
| `Messaging\Model\Api\QuotaReservation` | piso "bulk" em `centraliza_connector/delivery/bulk_quota_floor`; "bulk" agora são os eventos de conexão |
| `Messaging\Block\...\AuthenticateButton` + `authenticate.js` | valida a chave do ambiente salvo; se o seletor de ambiente foi trocado e não salvo, pede para salvar antes; avisa enquanto os antigos estiverem ativos |

### Fase 2: o que foi mantido de propósito

- **IDs de ACL, no mesmo lugar da árvore.** `Centraliza_Notification::notification_log`, `::notification_resend`, `::notification_send_now` ficam sob `Centraliza_Base::menu`, e `Centraliza_Notification::delivery_callback` sob `Magento_Config::config > Centraliza_Base::centraliza`, como nos antigos. Papéis e a Integration da Centraliza mantêm o acesso. O lugar importa: o mesmo id com outro pai entra duas vezes na ACL e o admin não carrega enquanto os antigos estiverem habilitados (conferido mesclando os `acl.xml` dos três). Por isso a raiz da ACL deste módulo passou a ser `Centraliza_Base::menu` (antes `Centraliza_Connector::centraliza_connector`), com Painel, Requisições enviadas e Notificações embaixo.
- **Rota do callback.** `POST /V1/centraliza/notification/sent`, a mesma URL registrada na Centraliza. Enquanto o `Centraliza_Sales` estiver instalado as duas declarações se mesclam numa rota só; as duas implementações atualizam a mesma linha de `centraliza_notification` do mesmo jeito.
- **Grupo de cron `centraliza_notification`**, com os mesmos valores, para um cron configurado para ele continuar rodando as notificações. O lock por chave (`centraliza_notification_send_<hash>`) também é o mesmo.
- **Tabela `centraliza_notification`**, as duas grades mostram as mesmas linhas.

**Convivência.** Enquanto o `Centraliza_Sales` estiver habilitado, nada daqui grava nem envia notificação: o observer exige `Messaging\Model\Config::isReady()`, e o cron só envia para os websites que `ApiKeyGrouping` devolve, que também exige `isReady()`. "Reenviar" na grade daqui só devolve a linha para `pending`, e quem a envia é o antigo. A limpeza de 90 dias roda nos dois, sem efeito.

Conferido no ambiente local (antigos desabilitados), em modo simulação: mudança de status de `pending` para `processing` grava a linha, o cron gera o mesmo corpo do antigo (`order.status_updated`, `order-<id>-processing`, contato com telefone E.164, `data.order` com id, número, status, rótulo, total, moeda e URL), e o callback move `delivered` para `sent`; id desconhecido dá 404 e chamada de admin é recusada.

### Fase 3: o que foi mantido de propósito

- **Rota do link** `/centraliza/cart_redirect/?cart_id=<masked id>` (route id e frontName `centraliza`), a dos links já enviados. Enquanto o `Centraliza_Sales` estiver instalado, as duas declarações se mesclam e o Magento usa o primeiro módulo da lista que tiver o controller. Este módulo vem depois dele: `Centraliza_Base` e `Centraliza_Sales` estão na `sequence` do `module.xml` (o Magento ignora um módulo da sequence que não está instalado) e o `routes.xml` declara `after="Centraliza_Sales"`. Conferido mesclando os dois `routes.xml`: `Centraliza_Sales > Centraliza_Connector`. A `sequence` muda a ordem dos módulos no `config.php` (o `Centraliza_Connector` passa para depois do `Centraliza_Sales`) no próximo `setup:upgrade`.
- **Tabelas** `centraliza_sales_abandoned_cart`, `centraliza_sales_cart_activity`, `centraliza_sales_cart_link` e as colunas `centraliza_masked_id`; **chave de sessão** `centraliza_cart_redirect_quote_id`; **locks** `centraliza_sales_abandoned_cart_send_<hash>` e `centraliza_sales_cart_redirect_<id>`. Um link aberto antes do corte continua valendo depois, e um visitante que abriu o link antes do deploy ainda tem o carrinho adotado no login.
- **IDs de ACL** `Centraliza_Sales::abandoned_cart` e `::abandoned_cart_send_now`, sob `Centraliza_Base::menu`, como no antigo.

**Convivência.** Os observers do antigo agem sem checar configuração (registro de atividade, troca de carrinho no login, link que segue o carrinho no merge, marca no pedido). Os daqui fazem o mesmo, e por isso não fazem nada enquanto o `Centraliza_Sales` estiver habilitado (`LegacyModules::areActive()`): os dois juntos trocariam o carrinho duas vezes no login. O envio de carrinho abandonado exige `isReady()`, como o de pedido.

Conferido no ambiente local (antigos desabilitados), em modo simulação: um carrinho de cliente parado há 5 minutos, com tempo de abandono de 1 minuto, foi selecionado e enviado com o corpo do antigo (`cart.abandoned`, `cart-<id>`, contato, `data.cart` com `masked_id` de 32 caracteres, guardado em `centraliza_sales_cart_link`). Pela loja: carrinho criado pela API `guest-carts`, como a Centraliza faz; `HEAD` redireciona sem alterar; `GET` de visitante leva a `/checkout/cart/`, marca o carrinho e o põe na sessão; id desconhecido volta ao carrinho com a mensagem de erro; link de carrinho de cliente sem login leva ao login; com o recurso desligado, o link cai no 404 da loja. A marca do link chega ao pedido; `order.created` continua dependendo de `order_created_enabled`. Não testado aqui: o login de um cliente que abriu o link (troca do carrinho dele), por falta de uma conta com senha conhecida no banco local.

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
