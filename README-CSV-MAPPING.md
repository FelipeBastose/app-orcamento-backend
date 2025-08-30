# Sistema de Mapeamento de CSV

Este sistema permite importar arquivos CSV de diferentes instituições bancárias usando mapeamentos parametrizados por cartão.

## Como Funciona

### 1. Mapeamentos Padrão

O sistema já vem configurado com mapeamentos padrão para:

- **Nubank**: Formato atual (data, lançamento, categoria, tipo, valor)
- **Inter**: Formato específico (data, lançamento, categoria, tipo, valor)
- **Inter Simples**: Formato alternativo (data, lançamento, valor)

### 2. Estrutura da Tabela CSV Mappings

```sql
csv_mappings
├── id
├── credit_card_id (FK para credit_cards)
├── name (Nome do mapeamento)
├── institution (Nubank, Inter, etc.)
├── column_mapping (JSON com mapeamento das colunas)
├── date_format (JSON com formatos de data aceitos)
├── amount_format (JSON com configurações de valor)
├── delimiter (Delimitador do CSV)
├── has_header (Se tem cabeçalho)
└── is_active (Se está ativo)
```

### 3. Exemplo de Mapeamento

```json
{
  "column_mapping": {
    "date": 0,        // Coluna 0: Data
    "description": 1,  // Coluna 1: Lançamento
    "category": 2,     // Coluna 2: Categoria
    "type": 3,         // Coluna 3: Tipo
    "amount": 4        // Coluna 4: Valor
  },
  "date_format": ["d/m/Y"],
  "amount_format": {
    "currency_symbol": "R$",
    "decimal_separator": ",",
    "thousands_separator": ".",
    "remove_currency_symbol": true
  }
}
```

## API Endpoints

### Listar Mapeamentos
```
GET /api/csv-mappings
```

### Obter Mapeamento Específico
```
GET /api/csv-mappings/{id}
```

### Criar Novo Mapeamento
```
POST /api/csv-mappings
{
  "credit_card_id": 1,
  "name": "Meu Mapeamento",
  "institution": "Banco XYZ",
  "column_mapping": {...},
  "date_format": ["d/m/Y"],
  "amount_format": {...}
}
```

### Atualizar Mapeamento
```
PUT /api/csv-mappings/{id}
```

### Excluir Mapeamento
```
DELETE /api/csv-mappings/{id}
```

### Obter por Instituição
```
GET /api/csv-mappings/institution/{institution}
```

### Testar Mapeamento
```
POST /api/csv-mappings/{id}/test
{
  "sample_data": ["09/08/2025", "PAGAMENTO ON LINE", "OUTROS", "Compra à vista", "R$ 422,39"]
}
```

## Como Usar

### 1. Upload Automático

Ao fazer upload de um CSV, o sistema automaticamente:

1. Identifica o cartão selecionado
2. Busca o mapeamento apropriado
3. Processa o arquivo usando as configurações
4. Importa as transações

### 2. Criação de Mapeamentos Personalizados

Para criar um mapeamento personalizado:

1. Identifique a estrutura do CSV da instituição
2. Configure o mapeamento das colunas
3. Defina os formatos de data e valor
4. Teste com dados de exemplo

### 3. Exemplo de CSV Inter

```
"Data","Lançamento","Categoria","Tipo","Valor"
"09/08/2025","PAGAMENTO ON LINE","OUTROS","Compra à vista","R$ 422,39"
"25/06/2025","TV GILSON","OUTROS","Parcela 3/6 da compra","R$ 129,20"
```

## Vantagens do Sistema

1. **Flexibilidade**: Suporta diferentes formatos de CSV
2. **Parametrização**: Cada cartão pode ter seu próprio mapeamento
3. **Manutenibilidade**: Fácil de adicionar novos formatos
4. **Testabilidade**: Pode testar mapeamentos antes de usar
5. **Fallback**: Usa mapeamentos padrão quando não há configuração específica

## Configuração de Novos Bancos

Para adicionar suporte a um novo banco:

1. Criar novo mapeamento via API
2. Configurar mapeamento de colunas
3. Definir formatos de data e valor
4. Testar com dados reais
5. Ativar o mapeamento

## Troubleshooting

### Problema: CSV não é processado
- Verificar se o mapeamento está ativo
- Confirmar se as colunas estão mapeadas corretamente
- Testar o mapeamento com dados de exemplo

### Problema: Datas não são reconhecidas
- Verificar se o formato está na lista de formatos aceitos
- Adicionar novos formatos se necessário

### Problema: Valores não são parseados
- Verificar configurações de formato de valor
- Confirmar símbolos de moeda e separadores
