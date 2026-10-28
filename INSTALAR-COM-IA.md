# Instalar com IA (um prompt só)

Em vez de subir os arquivos na mão, você cola um prompt no Claude e ele instala o Raio-X de Ofertas na sua HostGator pelo próprio navegador.

## Antes de colar o prompt, tenha estas 3 coisas

1. **Conta na AIsa com saldo e uma chave de API.** Crie em [aisa.one](https://aisa.one) → console → API Keys. Crie uma chave só para o robô e coloque um limite de uso nela.
2. **Hospedagem HostGator Plano M (ou maior), já ativa.** Ela tem PHP 8, banco SQLite e Tarefas Cron, que é o que o robô precisa.
3. **Claude com a extensão Claude in Chrome.** Pode ser o Claude Code ou o app do Claude no computador, com a extensão instalada e conectada ao seu Chrome.

## O que a IA faz e o que fica com você

| A IA faz | Você faz |
|---|---|
| Baixa os arquivos deste repositório | Contrata a HostGator e a AIsa |
| Cria as pastas e sobe tudo no cPanel, com as permissões certas | Entra no cPanel com a sua senha |
| Junta o `.htaccess` da HostGator sem apagar as linhas dela | Cola a chave da AIsa e cria a senha do painel na tela de Primeira configuração |
| Confere a página Saúde e explica o que estiver vermelho | Aprova a criação do agendamento (cron) e do domínio |
| Cria o agendamento e liga o domínio, depois de você aprovar | |

A IA **nunca** pede, lê ou digita a sua chave da AIsa nem as suas senhas. Se ela pedir, não passe.

## Como usar

1. No Chrome, entre no cPanel da HostGator (Área do Cliente → Hospedagens → Gerenciar → cPanel) e deixe a aba aberta.
2. Abra o Claude numa pasta vazia e confirme que ele enxerga o Chrome (no Claude Code, rode `/chrome`).
3. Copie o prompt abaixo inteiro, cole e envie.
4. Acompanhe. Quando ele parar e pedir, faça a sua parte (chave e senha) e responda `pronto`.

Leva alguns minutos. Se a sua conta HostGator for nova, o HTTPS pode dar erro 404 por alguns minutos: é normal, espere e recarregue.

Prefere fazer na mão? O passo a passo manual está no [README](README.md#3-subir-na-hostgator-cpanel).

## O prompt

```text
Instale o Raio-X de Ofertas (https://github.com/ArrecheNeto/raio-x-ofertas, branch main) na minha hospedagem HostGator, usando a extensão Claude in Chrome na aba do cPanel que já está aberta e logada.

REGRAS (siga sempre):
- Nunca peça, leia, digite nem salve a minha chave da AIsa ou qualquer senha. Não crie nem edite o arquivo .env: eu faço isso na tela de Primeira configuração.
- Não apague nada. Antes de substituir qualquer arquivo que já exista no meu servidor, me mostre qual é e espere eu aprovar.
- Antes de criar o agendamento (cron), ligar um domínio ou mudar a versão do PHP, me diga exatamente o que vai fazer e espere eu responder "sim".
- Não contrate, compre nem pague nada.
- Se uma etapa falhar duas vezes, pare e me explique o erro em português simples.

COMO FAZER (tudo por fetch em JavaScript na aba do cPanel; S = o trecho /cpsessNNNN da URL):
1. Conferir a aba: a URL tem :2083/cpsess. Se não tiver, me peça para entrar no cPanel. Pegue o usuário e a pasta inicial (HOME) em S/execute/Variables/get_user_information.
2. Escolher o destino do site: liste HOME/public_html (S/execute/Fileman/list_files). Se só tiver arquivos padrão da HostGator (cgi-bin, .htaccess, .well-known, página padrão), o destino é HOME/public_html. Se já existir outro site, pare e me pergunte se instalo em HOME/public_html/raiox ou num subdomínio.
3. Listar os arquivos do repositório em https://api.github.com/repos/ArrecheNeto/raio-x-ofertas/git/trees/main?recursive=1 (tem tamanho de cada arquivo). Mapeie:
   - app/...  -> HOME/raiox_app/...   (fica FORA da public_html: ali ficam a chave e o banco)
   - public/... -> DESTINO/...
   Ignore: qualquer .env (exceto app/.env.example), app/data/* (só crie a pasta HOME/raiox_app/data), public/.htaccess (vai no passo 5), .claude, scripts, dist e arquivos .md.
4. Criar as pastas com a API2 Fileman::mkdir (S/json-api/cpanel?cpanel_jsonapi_module=Fileman&cpanel_jsonapi_func=mkdir&cpanel_jsonapi_apiversion=2&path=PAI&name=NOME) e enviar cada arquivo com S/execute/Fileman/upload_files (FormData: dir + file-1 como Blob, baixado de https://raw.githubusercontent.com/ArrecheNeto/raio-x-ofertas/main/CAMINHO com response.blob() para manter os bytes). Depois confira com list_files (include_permissions=1) se cada arquivo existe e tem o mesmo tamanho do GitHub.
5. .htaccess: leia DESTINO/.htaccess (S/execute/Fileman/get_file_content). A HostGator coloca nele as linhas "# php -- BEGIN cPanel-generated handler" até "# php -- END cPanel-generated handler", que ligam o PHP: NUNCA remova nem substitua essas linhas. Se o arquivo não tiver a linha "Arquivos internos nunca sao servidos", acrescente o conteúdo de public/.htaccess do repositório no fim e salve com S/execute/Fileman/save_file_content. Se o arquivo não existir, crie com o conteúdo do repositório.
6. Permissões: arquivos 0644 e pastas 0755. Arquivo com 0666 ou 0777 faz a HostGator recusar o PHP. Corrija com a API2 Fileman::fileop (op=chmod, metadata=0644 ou 0755, sourcefiles=caminho).
7. Abrir o site em uma aba nova: https://MEU-DOMINIO/admin.php (o domínio principal aparece no cPanel em Domínios; se o destino for a pasta raiox, use /raiox/admin.php). Se aparecer 404 da HostGator ou erro de certificado em conta nova, espere 3 minutos e tente de novo (até 4 vezes). Quando abrir a tela "Primeira configuração", PARE e me diga: "Agora é com você: cole a sua chave da AIsa, crie a senha do painel (8 caracteres ou mais), deixe o teto diário em 2,50 e clique em Salvar. Depois me responda pronto." Faça isso logo, porque enquanto não tem senha qualquer pessoa pode configurar.
8. Depois do meu "pronto", eu já vou estar logado no painel. Abra /saude.php no mesmo site, leia os itens e me diga em uma lista curta o que está verde e o que está vermelho, com a correção de cada vermelho. Se o PHP for menor que 8.0, me peça para aprovar a troca para PHP 8.x (cPanel > MultiPHP Manager).
9. Agendamento: a página Saúde mostra o comando do cron com o caminho do PHP (use a linha que começa com /opt/cpanel/ea-php). Veja se já existe um cron igual (API2 Cron::listcron). Se não existir, me mostre o comando e, depois do meu "sim", crie com a API2 Cron::add_line: minute=*/15, hour=6-9, day=*, month=*, weekday=*, command="<comando da Saúde> >/dev/null 2>&1". Isso faz o robô acordar de 15 em 15 minutos entre 6h e 9h45 e coletar em pedaços pequenos.
10. Domínio (só se eu quiser): pergunte se eu comprei um domínio separado. Se sim e o site estiver em public_html, confira se os DNS dele apontam para os nameservers da minha HostGator (estão no cPanel, na barra lateral, ou na Área do Cliente) e, depois do meu "sim", crie como alias com a API2 Park::park (domain=MEU-DOMINIO). Avise que leva uns 5 minutos e que "Page cannot be displayed" nesse tempo é normal; o HTTPS sai sozinho.
11. No fim, me mande um resumo curto: o endereço do site, o endereço do painel, o que foi feito, o que ficou pendente, e o próximo passo: abrir o painel e clicar em "Rodar agora", ou usar o campo "Pesquisar agora" na página de Ofertas.
```

## Se der errado

- **"A extensão não está conectada":** abra o Chrome, confira se a extensão Claude in Chrome está ligada e rode `/chrome` de novo no Claude Code.
- **A IA pediu a sua chave ou senha:** não passe. Diga "não, eu faço isso na tela de Primeira configuração".
- **A página Saúde ficou vermelha:** copie o que está escrito em vermelho para a IA; cada item já diz como corrigir.
- **Quer recomeçar:** no cPanel, Gerenciador de arquivos, mova `raiox_app` e os arquivos do site para a lixeira e cole o prompt de novo.
