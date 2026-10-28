-- Schema unico para SQLite e MySQL. Os marcadores {ID}, {K} e {TXT} sao trocados pelo Db.php.

CREATE TABLE IF NOT EXISTS nichos (
  id {ID},
  nome {K} NOT NULL,
  termo {K} NOT NULL,
  ativo INTEGER NOT NULL DEFAULT 1,
  criado_em {K}
);

CREATE TABLE IF NOT EXISTS anunciantes (
  brand_id {K} PRIMARY KEY,
  nome {K},
  avatar {TXT},
  nicho_id INTEGER,
  tipo {K},
  primeiro_visto {K},
  ultimo_visto {K},
  anuncio_principal {K},
  max_dias INTEGER DEFAULT 0,
  ativos_atual INTEGER,
  ativos_fonte {K},
  page_id {K},
  status {K},
  delta7 REAL,
  delta14 REAL,
  atualizado_em {K}
);

CREATE TABLE IF NOT EXISTS anuncios (
  ad_id {K} PRIMARY KEY,
  fp_id {K},
  brand_id {K},
  nicho_id INTEGER,
  nome {K},
  headline {TXT},
  descricao {TXT},
  transcricao {TXT},
  link_url {TXT},
  dominio {K},
  cta_type {K},
  formato {K},
  plataformas {TXT},
  categoria {K},
  product_category {K},
  tipo {K},
  dias_no_ar INTEGER DEFAULT 0,
  iniciou_em {K},
  thumbnail {TXT},
  video {TXT},
  imagem {TXT},
  live INTEGER DEFAULT 1,
  hash_conteudo {K},
  primeiro_visto {K},
  ultimo_visto {K}
);

CREATE TABLE IF NOT EXISTS vistos (
  data {K} NOT NULL,
  ad_id {K} NOT NULL,
  brand_id {K} NOT NULL,
  PRIMARY KEY (data, ad_id)
);

CREATE TABLE IF NOT EXISTS analytics (
  brand_id {K} NOT NULL,
  data {K} NOT NULL,
  ativos INTEGER,
  inativos INTEGER,
  videos INTEGER,
  imagens INTEGER,
  PRIMARY KEY (brand_id, data)
);

CREATE TABLE IF NOT EXISTS raiox (
  ad_id {K} PRIMARY KEY,
  brand_id {K},
  modelo {K},
  hash_conteudo {K},
  json {TXT},
  criado_em {K}
);

CREATE TABLE IF NOT EXISTS fila (
  id {ID},
  tipo {K} NOT NULL,
  ref {K} NOT NULL,
  data {K} NOT NULL,
  status {K} NOT NULL DEFAULT 'pendente',
  tentativas INTEGER NOT NULL DEFAULT 0,
  erro {TXT},
  criado_em {K},
  atualizado_em {K}
);

CREATE TABLE IF NOT EXISTS custos (
  id {ID},
  criado_em {K},
  data {K},
  tipo {K},
  endpoint {K},
  micros INTEGER NOT NULL DEFAULT 0,
  fonte {K},
  http INTEGER,
  itens INTEGER,
  ref {K}
);

CREATE TABLE IF NOT EXISTS erros (
  id {ID},
  criado_em {K},
  contexto {K},
  mensagem {TXT}
);

CREATE TABLE IF NOT EXISTS execucoes (
  id {ID},
  inicio {K},
  fim {K},
  segundos REAL,
  jobs INTEGER,
  origem {K},
  resumo {TXT}
);

CREATE TABLE IF NOT EXISTS config (
  chave {K} PRIMARY KEY,
  valor {TXT}
);

-- Modo Concorrente: anunciantes que voce escolhe acompanhar todo dia (por dominio ou pela pagina da oferta)
CREATE TABLE IF NOT EXISTS concorrentes (
  id {ID},
  nome {K},
  dominio {K},
  brand_id {K},
  ad_library_id {K},
  candidatos {TXT},
  ativo INTEGER DEFAULT 1,
  status {K},
  erro {TXT},
  ultima_coleta {K},
  criado_em {K}
);

CREATE UNIQUE INDEX {IFNE} fila_unica ON fila (tipo, ref, data);
CREATE INDEX {IFNE} anuncios_brand ON anuncios (brand_id);
CREATE INDEX {IFNE} vistos_brand ON vistos (brand_id, data);
CREATE INDEX {IFNE} custos_data ON custos (data);
