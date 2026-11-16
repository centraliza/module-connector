# Centraliza_Connector: relatório de simulação

Michel, este é o resultado de uma rodada de simulação do novo módulo Magento, feita em 13/11/2026 em ambiente local da loja EPI Zeus (Magento 2.4.7, MSI ativo). Nenhuma requisição foi enviada ao Centraliza. O módulo gravou em um log o que teria enviado, e este relatório resume esse log.

O objetivo é validar com você o formato dos eventos e tirar as dúvidas da última seção antes de ligarmos o envio real.

## O que foi exercitado

O módulo captura alterações de produto e de estoque por observers e plugins, espera o commit da transação e publica um evento por registro na fila do Magento. Um consumer lê a fila e faz o envio. Nesta rodada o envio foi substituído por um transport de simulação.

Seguimos o contrato de `POST /v1/admin/events` para eventos de conexão: `type` no formato `{stream}.{created|updated|deleted}`, `connection` preenchido e `data` só com a chave do registro.

| Medida | Valor |
|---|---|
| Operações executadas na loja | 21 |
| Requisições que seriam enviadas | 23 |
| `products.updated` | 9 |
| `stock.updated` | 6 |
| `products.created` | 3 |
| `products.deleted` | 3 |
| `stock.deleted` | 2 |
| `idempotency_key` distintas | 23 |
| Eventos em retry ou mortos | 0 |

## Regras de envio

1. **Produto só gera evento quando o conteúdo muda.** O módulo compara o que foi carregado com o que foi salvo: atributos, websites, categorias, preços por quantidade e galeria de imagens. Um save que não altera nada não gera evento.
2. **Estoque só gera evento quando quantidade ou status mudam.** O Magento regrava o item de estoque em todo save de produto; isso não gera `stock.updated`.
3. **Troca de SKU gera um único evento**, `products.updated`, com o SKU novo em `sku` e o antigo em `previous_sku`.
4. **Apagar um produto gera `products.deleted` e um `stock.deleted` por source** em que ele tinha estoque, no mesmo momento.
5. **O envio é condicionado por store view.** Só geram evento os produtos atribuídos a um website que tenha uma store view com o conector ligado. Nesta rodada só a store view da EPI Zeus estava ligada.

## Formato das requisições

Todas vão para `POST https://api.centraliza.ai/v1/admin/events` com `Authorization: Bearer csk_...`, `Content-Type: application/json` e `Accept: application/json`. O `connection` aparece como `<connection-id>` porque ainda não temos o id configurado.

Estoque alterado:

```json
{
  "connection": "<connection-id>",
  "source": "magento",
  "type": "stock.updated",
  "idempotency_key": "104b96c6-f1d8-4d20-9065-fa3fa46454e4",
  "occurred_at": "2026-11-14T02:24:05Z",
  "data": {
    "sku": "ZC-TESTE-A",
    "source_code": "default"
  }
}
```

Troca de SKU:

```json
{
  "connection": "<connection-id>",
  "source": "magento",
  "type": "products.updated",
  "idempotency_key": "ac960d7c-d788-4547-a83a-879b796802fb",
  "occurred_at": "2026-11-14T02:24:07Z",
  "data": {
    "sku": "ZC-TESTE-C2",
    "previous_sku": "ZC-TESTE-C"
  }
}
```

Estoque removido junto com o produto:

```json
{
  "connection": "<connection-id>",
  "source": "magento",
  "type": "stock.deleted",
  "idempotency_key": "18a6a58e-9e27-4f4a-8541-5e4b84f892c4",
  "occurred_at": "2026-11-14T02:24:08Z",
  "data": {
    "sku": "ZC-TESTE-B",
    "source_code": "default"
  }
}
```

- `source` é sempre `magento`.
- `idempotency_key` é um UUID v4 gerado na captura. O mesmo valor é reenviado em todo retry daquele evento.
- `occurred_at` é o instante do commit, em UTC.
- Não enviamos `contact`.

## Operação por operação

| # | Operação | Caminho no Magento | Requisições | Quais |
|---|---|---|---|---|
| 1 | Criar produto simples com estoque 10 | `ProductRepositoryInterface::save` | 2 | `stock.updated` ZC-TESTE-A + default, `products.created` ZC-TESTE-A |
| 2 | Alterar o nome do produto | `ProductRepositoryInterface::save` | 1 | `products.updated` ZC-TESTE-A |
| 3 | Salvar o produto sem alterar nada | `ProductRepositoryInterface::save` | 0 | nenhuma |
| 4 | Alterar o preço do produto | `ProductRepositoryInterface::save` | 1 | `products.updated` ZC-TESTE-A |
| 5 | Alterar a quantidade no source default para 25 | `SourceItemsSaveInterface::execute` | 1 | `stock.updated` ZC-TESTE-A + default |
| 6 | Regravar a mesma quantidade (25) | `SourceItemsSaveInterface::execute` | 0 | nenhuma |
| 7 | Alterar a quantidade pela API legada de estoque | `StockRegistryInterface::updateStockItemBySku` | 1 | `stock.updated` ZC-TESTE-A + default |
| 8 | Criar mais dois produtos | `ProductRepositoryInterface::save` | 4 | `stock.updated` ZC-TESTE-B + default, `products.created` ZC-TESTE-B, `stock.updated` ZC-TESTE-C + default, `products.created` ZC-TESTE-C |
| 9 | Criar produto só no website que não usa Centraliza | `ProductRepositoryInterface::save` | 0 | nenhuma |
| 10 | Alterar nome e estoque do produto fora do Centraliza | `ProductRepositoryInterface::save + SourceItemsSave` | 0 | nenhuma |
| 11 | Atualização em massa de atributo em 4 produtos (1 fora do Centraliza) | `Catalog\Model\Product\Action::updateAttributes` | 3 | `products.updated` ZC-TESTE-A, `products.updated` ZC-TESTE-B, `products.updated` ZC-TESTE-C |
| 12 | Adicionar ao website do Centraliza o produto que estava fora | `Catalog\Model\Product\Action::updateWebsites` | 1 | `products.updated` ZC-TESTE-X |
| 13 | Remover esse produto do website do Centraliza | `Catalog\Model\Product\Action::updateWebsites` | 1 | `products.updated` ZC-TESTE-X |
| 14 | Renomear o SKU de um produto | `ProductRepositoryInterface::save` | 1 | `products.updated` ZC-TESTE-C2 + ZC-TESTE-C |
| 15 | Alterar o produto em transação que sofre rollback | `ProductResource::save + rollBack` | 0 | nenhuma |
| 16 | Salvar o mesmo produto 10 vezes em uma transação | `ProductResource::save x10 + commit` | 1 | `products.updated` ZC-TESTE-A |
| 17 | Baixa de estoque de 2 unidades (caminho do pedido) | `DecrementSourceItemQty::execute` | 1 | `stock.updated` ZC-TESTE-A + default |
| 18 | Apagar um produto | `ProductRepositoryInterface::delete` | 2 | `products.deleted` ZC-TESTE-B, `stock.deleted` ZC-TESTE-B + default |
| 19 | Limpeza de estoque do produto apagado (consumer do Magento) | `inventory.source.items.cleanup` | 0 | nenhuma |
| 20 | Apagar o produto fora do Centraliza | `ProductRepositoryInterface::delete` | 0 | nenhuma |
| 21 | Apagar os produtos restantes do teste e limpar o estoque | `ProductRepositoryInterface::delete + cleanup` | 3 | `products.deleted` ZC-TESTE-A, `stock.deleted` ZC-TESTE-A + default, `products.deleted` ZC-TESTE-C2 |

## O que a rodada mostrou

- Save sem alteração não gera requisição (operação 3), e regravar a mesma quantidade também não (operação 6).
- Alterar nome ou preço gera só `products.updated`, sem evento de estoque (operações 2 e 4).
- Produto de website sem conector não gera nada: criação, alteração, estoque e exclusão (operações 9, 10 e 20). Na atualização em massa de 4 produtos, só os 3 cobertos geraram evento (operação 11).
- Entrar em um website com conector, ou sair dele, gera `products.updated` (operações 12 e 13).
- Transação desfeita não gera evento (operação 15), e dez gravações na mesma transação geram um (operação 16).
- A baixa de estoque do caminho de pedido, que é um UPDATE direto, gera `stock.updated` (operação 17).

**Pontos de atenção**

1. **Não existe evento de preço.** A alteração de preço (operação 4) saiu como `products.updated`, porque só `products` e `stock` estão na documentação.
2. **Estoque após troca de SKU.** O Magento não move o estoque para o SKU novo: o item de estoque continua gravado com o SKU antigo até a próxima gravação de estoque do produto. Por isso a troca (operação 14) não gerou evento de estoque, e a exclusão desse produto (operação 21) não gerou `stock.deleted` para ele. Se o Centraliza reler o estoque do SKU novo logo após a troca, não vai encontrar nada.
3. **Limite da detecção de mudança em produto.** Alterações só em produtos relacionados, opções personalizáveis ou associações de configurável e bundle não são detectadas como mudança e não geram evento.
4. **Sair do website com conector** gera `products.updated`, não `products.deleted`. Precisamos saber qual dos dois você espera.

## Perguntas para você

1. Os streams `products` e `stock` aceitam o Magento como origem? O guia do conector Magento descreve produtos e estoque indo do Centraliza para o Magento.
2. O id que vai em `connection` é o `id` do canal devolvido por `GET /v1/admin/channels`?
3. `created` e `updated` têm tratamento diferente, ou podemos enviar sempre `updated`?
4. Para `stock`, as chaves `sku` e `source_code` estão corretas? Hoje a loja tem um único source, `default`.
5. A troca de SKU como `products.updated` com `previous_sku` funciona para você? O nome do campo pode ser esse?
6. Quando um produto sai do website ligado ao Centraliza, você espera `products.updated` ou `products.deleted`?
7. Qual política de remoção se aplica em `.deleted` para cada stream?
8. Existem ou estão previstos streams para preço, cliente, grupo de clientes, pedido, carrinho e centro de distribuição? Se sim, quais são os nomes e as chaves? Para cliente, vale o stream ou os eventos `contact.created` e `contact.updated`?
9. Como o Centraliza relê o registro: pela REST do Magento com o token de integração do conector? Precisamos saber para liberar os recursos certos no token.
10. Qual é o limite de `POST /v1/admin/events`? A página de convenções diz 6.000 por minuto e o guia de API keys diz 60.

## Limites desta rodada

- Rodou em ambiente local, com dados de teste criados e removidos no fim, e fila do tipo banco de dados. Não testamos com RabbitMQ.
- O envio HTTP real não foi exercitado, então retry, `429` e circuit breaker só foram verificados por testes unitários.
- Só produto e estoque têm captura. Importações do ERP e escrita por SQL direto não geram evento.
