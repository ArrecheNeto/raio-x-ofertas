# Raio-X de Ofertas

Robô que todo dia minera anúncios de infoproduto em português que estão rodando há mais tempo no Facebook e no Instagram, guarda o histórico de cada anunciante para mostrar quem está **escalando**, **estável** ou **caindo**, desmonta o criativo com IA e publica tudo numa página no seu domínio.

Feito para hospedagem compartilhada (HostGator Plano M, cPanel): PHP puro, banco SQLite em um arquivo, sem Node, sem Composer.

> Dias no ar e número de anúncios são sinais de escala, não comprovação de vendas.

---

## Como funciona, em 1 minuto

1. **Coleta (de manhã):** para cada nicho, busca anúncios ativos em português no Facebook, ordenados pelos que rodam há mais tempo. Guarda o anúncio (texto, transcrição, vídeo, imagem, link da página) sem duplicar.
2. **Contagem real:** para os 20 maiores anunciantes do dia, busca quantos anúncios ativos eles têm (Biblioteca de Anúncios).
3. **Curva de escala:** compara hoje com 7 e 14 dias atrás. Mais de 30% de alta é ESCALANDO, mais de 30% de queda é CAINDO. Nos primeiros dias aparece "coletando histórico".
4. **Raio-X com IA:** só para ofertas novas ou que mudaram. Gancho, promessa, mecanismo único, prova, oferta, público, formato, 3 ângulos para criar a sua versão e 5 ideias de gancho.
5. **Página pública:** `index.php` com filtros e `oferta.php` com o criativo, a transcrição, o Raio-X e o histórico.

O trabalho é dividido numa **fila**. Cada execução do cron trabalha por até 25 segundos e termina, e a próxima continua de onde parou. Isso respeita o limite de tempo da hospedagem compartilhada.

## Quanto custa

Valores reais observados na AIsa em outubro de 2026:

| Item | Custo |
|---|---|
| Cada anúncio coletado | US$ 0,0175 |
| Cada dia de contagem de um anunciante | US$ 0,0175 |
| Achar a página do Facebook de um anunciante (uma vez só, para a contagem) | até US$ 0,05 |
| Cada Raio-X com IA (modelo deepseek-v4.1-flash) | cerca de US$ 0,001 |
| Cada concorrente acompanhado (20 anúncios + 1 dia de contagem) | cerca de US$ 0,37 por dia |
| Achar a marca de um concorrente pelo domínio | até US$ 0,05, uma vez só |
| Checagem de conta e saldo | grátis |

Com 5 nichos de 20 anúncios e o top 20 com contagem: cerca de **US$ 2,10 por dia** e **US$ 63 por mês**. No primeiro dia é um pouco mais, porque busca 7 dias de histórico para cada anunciante novo no top. O teto diário (`DAILY_BUDGET_USD`) para tudo quando é atingido e registra no painel.

**Como a contagem de anúncios ativos funciona:** a AIsa só devolve a contagem certa com o número da página do Facebook do anunciante. O robô descobre esse número uma vez, pelo site próprio do anunciante, e guarda no banco. Anunciante que só usa link de checkout, WhatsApp ou YouTube não tem como ser identificado. Nesse caso o robô pula a contagem sem gastar nada e usa os anúncios vistos por dia.

Para gastar menos, diminua `ADS_PER_NICHE`, o número de nichos ou `ANALYTICS_TOP` no `.env`.

---

## Jeito mais fácil: a IA instala para você

Tem o Claude com a extensão Claude in Chrome? Abra o [INSTALAR-COM-IA.md](INSTALAR-COM-IA.md), copie o prompt e cole. Ele sobe os arquivos no seu cPanel, junta o `.htaccess`, acerta as permissões, confere a página Saúde e cria o cron depois que você aprovar. A chave da AIsa e a senha do painel você mesmo coloca. Antes, tenha a conta na AIsa com saldo e a HostGator ativa.

Prefere fazer na mão? Siga os passos abaixo.

## 1. Pegar a chave da AIsa

1. Acesse **https://aisa.one** e entre na sua conta (ou crie uma).
2. Abra o **Console** e vá em **API Keys**.
3. Clique para criar uma chave nova. Dê um nome, por exemplo `raio-x-hostgator`.
4. **Recomendado:** defina um **limite de gasto** na chave (spending limit). É a trava mais forte: mesmo se algo der errado, a AIsa não deixa passar disso.
5. Copie a chave. Ela vai no arquivo `.env` (passo 3). **Nunca** cole a chave em página, print ou GitHub.
6. Coloque saldo na conta, se ainda não tiver.

## 2. Gerar o .zip

No seu computador, dentro da pasta do projeto:

```bash
php scripts/gerar-zip.php
```

Isso cria `dist/raio-x-ofertas-hostgator.zip` com duas pastas:

- `public_html/`: o que fica visível no navegador.
- `raiox_app/`: o robô, a configuração e o banco. Fica **fora** da pasta pública.

(O `.zip` nunca leva o seu `.env` nem o banco local.)

## 3. Subir na HostGator (cPanel)

### 3.1 Escolher onde a página vai ficar

- **No domínio principal** (seudominio.com.br): a pasta pública é `/home/SEU_USUARIO/public_html`.
- **Num subdomínio** (ex.: raiox.seudominio.com.br): no cPanel, abra **Domínios** (ou **Subdomínios**), crie o subdomínio e anote a **Raiz do documento**, por exemplo `/home/SEU_USUARIO/raiox.seudominio.com.br`.
- **Conta nova com endereço temporário** (`...meusitehostgator.com.br`) e um domínio comprado depois:
  1. Na Área do Cliente da HostGator, em **Domínios > Configurar domínio**, confira que o domínio usa os servidores DNS da sua hospedagem (ex.: `ns262.hostgator.com.br` e `ns263.hostgator.com.br`).
  2. No cPanel, em **Domínios**, crie o domínio como **alias** (ou "estacionado") do domínio principal. Ele passa a mostrar o mesmo site da `public_html`, sem mover nada.
  3. Espere de 2 a 10 minutos. Antes disso aparece "Page cannot be displayed": é normal. O certificado HTTPS sai sozinho (AutoSSL).

### 3.2 Enviar os arquivos

1. No cPanel, abra o **Gerenciador de Arquivos**.
2. Entre na sua pasta inicial, `/home/SEU_USUARIO`.
3. Clique em **Carregar** e envie o `raio-x-ofertas-hostgator.zip`.
4. Clique com o botão direito no `.zip` e escolha **Extrair**. Vão aparecer `public_html` e `raiox_app`.
5. Organize assim:
   - A pasta **`raiox_app`** fica em `/home/SEU_USUARIO/raiox_app` (ao lado da `public_html`, nunca dentro).
   - O **conteúdo** de `public_html` do zip (index.php, oferta.php, admin.php, saude.php, `_app.php`, `.htaccess` e a pasta `assets`) vai para a pasta pública escolhida no passo 3.1. Se for o domínio principal e a `public_html` já tiver um site, use um subdomínio para não misturar.
   - **Cuidado com o `.htaccess`:** a HostGator já cria um `.htaccess` na pasta pública com as linhas que ligam o PHP (começam em `# php -- BEGIN cPanel-generated handler`). **Não substitua esse arquivo.** Abra o existente em **Editar**, vá até o fim e cole o conteúdo do nosso `.htaccess` embaixo. Se substituir, o site para de rodar PHP.
   - Se a página der erro 500 ou 404 depois de extrair, confira as permissões no passo 3.4: arquivos PHP com 666 não rodam na HostGator.
6. Apague o `.zip` do servidor.

> Para ver o `.htaccess` no Gerenciador de Arquivos, clique em **Configurações** e marque **Mostrar arquivos ocultos**.

### 3.3 Versão do PHP

1. No cPanel, abra **Selecionar versão do PHP** (ou **MultiPHP Manager**).
2. Escolha **PHP 8.1 ou mais novo** para o domínio ou subdomínio.
3. Se houver a aba **Extensões**, confirme que estão marcadas: `curl`, `pdo_sqlite`, `sqlite3`, `mbstring`, `zip`.

### 3.4 Permissões

No Gerenciador de Arquivos (botão direito > **Alterar permissões**):

- Pastas: **755**.
- Arquivos: **644**.
- O `.env`: **600** (só o seu usuário lê).
- A pasta `raiox_app/data` precisa de gravação: **755** basta na HostGator.

### 3.5 Colocar a chave e criar a senha (pelo navegador)

1. Abra `https://seudominio.com.br/admin.php`.
2. Na primeira vez aparece a tela **Primeira configuração**. Preencha:
   - **Chave da AIsa:** a chave do passo 1. Ela é testada na hora, de graça, antes de salvar.
   - **Senha do painel:** pelo menos 8 caracteres.
   - **Teto de gasto por dia:** por exemplo `2.50`.
3. Clique em **Salvar e entrar**.

O robô grava tudo no arquivo `raiox_app/.env`, fora da pasta pública. A chave nunca mais aparece na tela, só os 4 últimos caracteres para você saber qual está salva.

> Faça isso logo depois de subir os arquivos. Enquanto não existe senha, qualquer pessoa que abrir o `/admin.php` consegue fazer a primeira configuração.

Para trocar a chave, a senha ou o teto depois, use **Configurações**, no fim do painel.

**Se aparecer "Não consegui gravar o arquivo .env":** a pasta `raiox_app` está sem permissão de escrita. Ajuste para 755. Ou crie o `.env` na mão: copie o `.env.example`, renomeie para `.env` e preencha `AISA_API_KEY`, `ADMIN_PASSWORD` e `DAILY_BUDGET_USD`.

### 3.6 Se o SQLite não estiver disponível

A `/saude.php` avisa. Nesse caso:

1. No cPanel, abra **Bancos de dados MySQL**, crie um banco, um usuário, e adicione o usuário ao banco com todos os privilégios.
2. No `.env`, troque para `DB_DRIVER=mysql` e preencha `DB_NAME`, `DB_USER` e `DB_PASS` (o `DB_HOST` fica `localhost`).
3. As tabelas são criadas sozinhas na primeira abertura.

## 4. Configurar a Tarefa Cron

1. No cPanel, abra **Tarefas Cron** (Cron Jobs).
2. Em **Adicionar nova tarefa cron**, preencha:
   - Minuto: `*/15`
   - Hora: `6-9`
   - Dia: `*`
   - Mês: `*`
   - Dia da semana: `*`
3. Em **Comando**, cole (troque `SEU_USUARIO`):

```bash
/usr/local/bin/php /home/SEU_USUARIO/raiox_app/cron/rodar.php >/dev/null 2>&1
```

Se não funcionar, use o caminho que a `/saude.php` mostra, normalmente:

```bash
/opt/cpanel/ea-php82/root/usr/bin/php /home/SEU_USUARIO/raiox_app/cron/rodar.php >/dev/null 2>&1
```

Isso roda de 15 em 15 minutos entre 6h e 9h45. Com 5 nichos, a coleta termina por volta das 7h e o Raio-X segue até o fim da janela. O horário do cron é o do servidor da HostGator, que pode não ser o de Brasília: confira a hora na `/saude.php` e ajuste se precisar.

> Quer acompanhar o que o cron faz? Troque `>/dev/null 2>&1` por `>> /home/SEU_USUARIO/raiox_app/data/cron.log 2>&1`.

## 5. Testar pela /saude.php

1. Entre no painel com a senha que você criou.
2. Abra `https://seudominio.com.br/saude.php`.
3. Tudo precisa estar verde: versão do PHP, cURL, SQLite, gravação em disco, banco, chave e **conexão com a AIsa** (mostra o seu saldo; essa checagem é grátis).
4. Volte ao **Painel** e clique em **Rodar agora** para fazer a primeira coleta sem esperar o cron. Cada clique trabalha 25 segundos; clique até a fila do dia acabar.
5. Abra a página inicial: as ofertas aparecem ali.

## O painel (admin.php)

- **Gasto hoje e no mês:** soma do custo real que a AIsa devolve em cada chamada (header `X-AISA-Customer-Cost-Micros-USD`).
- **Rodar agora:** continua a fila do dia.
- **Fila de hoje:** o que já rodou, o que falta e o que deu erro. Tarefas com erro **não** são repetidas sozinhas; use **Tentar de novo**.
- **Configurações:** trocar a chave da AIsa, a senha, o teto diário e quantos anúncios buscar.
- **Acesso e agendamento** (dentro de Configurações):
  - **Agendamento automático:** desmarcado, a Tarefa Cron continua no cPanel mas não faz nada. Você roda quando quiser pelo botão **Rodar agora**.
  - **Site só para mim:** as ofertas só abrem com login. Bom para gravar vídeo sem expor o site.
  - **Esconder o botão Entrar:** some do menu; você entra direto pelo `/admin.php`.
  - Não existe cadastro: o único acesso é a sua senha. Depois de 5 senhas erradas do mesmo IP (ou 30 no total) em 15 minutos, o login trava por 15 minutos.
- **Nichos:** adicionar, renomear, trocar o termo de busca, pausar ou remover.
- **Concorrentes:** acompanhar anunciantes específicos. Veja o Modo Concorrente abaixo.
- **Erros recentes e execuções:** quanto tempo cada rodada levou.

### Erros da AIsa

| Código | O que quer dizer | O que fazer |
|---|---|---|
| 401 | Chave errada ou ausente | Confira `AISA_API_KEY` no `.env` |
| 402 | Sem saldo, limite da chave ou teto de preço | Coloque saldo ou aumente o limite da chave |
| 429 | Muitas requisições | Espere; a próxima execução continua |
| Tempo esgotado | A AIsa demorou demais | Use **Tentar de novo** no painel |

O robô nunca repete uma chamada paga sozinho e, ao receber 401, 402 ou 429, para a execução na hora.

## Ajustes no .env

| Chave | Para que serve |
|---|---|
| `ADS_PER_NICHE` | Anúncios buscados por nicho por dia (padrão 20) |
| `BUSCA_EDUCACAO` | 1 faz uma segunda busca por nicho só com anúncios de educação (poucos itens, quase todos infoproduto); 0 desliga |
| `ANALYTICS_TOP` | Quantos anunciantes recebem a contagem real por dia (padrão 20; 0 desliga) |
| `ANALYTICS_BACKFILL_DAYS` | Dias de histórico na primeira vez que um anunciante entra no top (padrão 7) |
| `MODO_CONCORRENTE` | 1 liga o Modo Concorrente; 0 desliga sem apagar a lista |
| `CONCORRENTE_ADS` | Anúncios ativos buscados por concorrente por dia (padrão 20) |
| `MAX_CONCORRENTES` | Quantos concorrentes podem ser cadastrados (padrão 10) |
| `AGENDAMENTO_ATIVO` | 1 deixa o cron coletar sozinho; 0 desliga (o botão Rodar agora continua) |
| `SITE_PRIVADO` | 1 faz as ofertas só abrirem com login |
| `ESCONDER_ENTRAR` | 1 esconde o botão Entrar do menu |
| `RAIOX_POR_DIA` | Máximo de Raio-X novos por dia (padrão 30) |
| `AI_MODEL` | Modelo de IA da AIsa (padrão `deepseek-v4.1-flash`; reserva `gpt-4o-mini`) |
| `SO_INFOPRODUTO` | 1 mostra só infoproduto; 0 mostra tudo |
| `ESCALA_LIMIAR` | Variação mínima para ESCALANDO ou CAINDO (0.30 = 30%) |
| `MAX_PRICE_PER_CALL_USD` | Valor do header `X-AISA-Max-Price-USD`. Não baixe de 0.90: a AIsa estima 0,875 por chamada do Foreplay e recusaria. |

**Como o robô reconhece infoproduto:** página de destino em Hotmart, Kiwify, Eduzz, Monetizze, Ticto, Braip, Perfect Pay, Kirvano e similares, ou pelo menos dois termos como "curso", "aula", "mentoria", "ebook" e "treinamento" no anúncio. O resto fica salvo como "outro" e aparece na página marcando "Incluir produtos físicos e serviços".

## Rodar no seu computador

Precisa do PHP 8 com as extensões `curl` e `pdo_sqlite`.

```bash
cp app/.env.example app/.env
```

Edite `app/.env` (chave e senha). Depois:

```bash
php app/cron/rodar.php --tempo=600 --coletas=2
```

```bash
php -S localhost:8000 -t public
```

Abra http://localhost:8000.

## Estrutura

```
public/            (vira public_html na hospedagem)
  index.php        ofertas da semana, filtros por nicho e status
  oferta.php       criativo, transcrição, Raio-X e histórico
  admin.php        painel protegido por senha
  saude.php        testes do servidor (protegida)
  _app.php         encontra a pasta raiox_app
  assets/          style.css e app.js
app/               (vira raiox_app, fora da pasta pública)
  .env.example     modelo de configuração
  bootstrap.php
  cron/rodar.php   o que a Tarefa Cron executa
  lib/             Aisa, Coletor, Concorrente, Analytics, Escala, RaioX, Fila, Robo, Custos, Db
  sql/schema.sql   tabelas (SQLite e MySQL)
  data/            banco SQLite e lock (criado sozinho)
scripts/gerar-zip.php
```

## Pesquisar na hora

Logado, a página de Ofertas mostra o campo **Pesquisar agora**. Digite um nicho ou produto (ex.: `curso de confeitaria`, `inglês`) e o robô:

1. busca os anúncios em português mais relevantes para o termo (20 por padrão, `ADS_PER_NICHE`);
2. descarta os que não citam as palavras do termo (sem acento, todas as palavras);
3. calcula a escala e faz o Raio-X dos 3 principais;
4. abre a lista filtrada por esse termo.

Com **Acompanhar todo dia** marcado, o termo vira um nicho da coleta diária. Desmarcado, ele fica salvo como nicho pausado (dá para ligar depois no painel). Cada pesquisa custa como uma coleta de nicho (cerca de US$ 0,35 com 20 anúncios) e respeita o teto diário. Para visitantes o campo não aparece.

## Modo Concorrente

Serve para acompanhar todo dia anunciantes que você escolhe, como seus concorrentes diretos, mesmo que eles não apareçam na busca dos nichos.

**Como adicionar:**
- No painel, em **Concorrentes**, digite o domínio do site (ex.: `concorrente.com.br`) e clique em **Adicionar concorrente**. Na próxima execução o robô procura qual anunciante usa esse domínio. Se achar mais de um, você escolhe o certo na lista.
- Ou abra qualquer oferta já coletada (logado no painel) e clique em **Acompanhar como concorrente**. Nesse caso não há busca paga, porque o anunciante já é conhecido.

**O que o robô faz todo dia:** busca os anúncios ativos de cada concorrente (os que estão no ar há mais tempo primeiro), soma a contagem real de anúncios ativos e calcula ESCALANDO, ESTÁVEL ou CAINDO, como nas ofertas. O anúncio principal de cada concorrente também recebe o Raio-X com IA, mesmo que não seja infoproduto.

**Onde ver:** no menu **Concorrentes** (só aparece logado). A lista é sua e não fica pública. O teto diário continua valendo para tudo.
