HANDMANAGER v3.0 — PHP + MYSQL / MARIADB
=========================================
Época de referência: 2026/2027

O HandManager é uma aplicação local de gestão desportiva de andebol.
A versão v3.0 junta numa única interface:
- atletas e fichas individuais;
- equipas e plantéis;
- escalões e inscrições múltiplas por época;
- equipa técnica;
- competições;
- jogos, resultados e calendário;
- convocatórias;
- eventos de jogo e estatísticas automáticas;
- treinos e assiduidade;
- lesões e acompanhamento de recuperação;
- relatórios CSV;
- pesquisa global;
- fontes externas FPA / zerozero;
- utilizadores e perfis de acesso;
- registo de atividade quando a tabela de auditoria está disponível.

REQUISITOS
----------
- XAMPP / Apache + MySQL ou MariaDB
- PHP 8.x
- Navegador moderno

INSTALAÇÃO NOVA
---------------
1. Copia a pasta HandManager para:
   C:\xampp\htdocs\HandManager\

2. Inicia Apache e MySQL no XAMPP.

3. Abre o phpMyAdmin:
   http://localhost/phpmyadmin/

4. Importa:
   database.sql

   ATENÇÃO: database.sql é para uma instalação limpa e recria as tabelas.

5. Confirma os dados da ligação em:
   config/config.php

6. Abre:
   http://localhost/HandManager/setup.php

7. Cria o primeiro administrador e entra em:
   http://localhost/HandManager/login.php

ATUALIZAR UMA BASE QUE JÁ TEM OS 344 ATLETAS / DADOS EXISTENTES
---------------------------------------------------------------
NÃO importes database.sql se queres conservar os teus dados atuais.

No phpMyAdmin seleciona a base "handmanager" e importa:
   upgrade_2026_2027.sql

Este ficheiro foi preparado para acrescentar os novos campos e módulos sem
apagar atletas, equipas, jogos, treinos ou utilizadores já existentes.

Depois abre Definições > Diagnóstico no HandManager. A página indica quais
os módulos que já existem na base de dados.

MÓDULOS NOVOS / MELHORADOS
--------------------------
Dashboard
- KPIs de atletas, jogos, treinos e indisponíveis.
- Alertas operacionais.
- Performance recente.
- Top marcadores.
- Próximos jogos e treinos.
- Competições da época.

Atletas
- CIPA, sexo, nacionalidade, fotografia, data de nascimento.
- Dados físicos e mão dominante.
- Posição, camisola, equipa principal e origem dos dados.
- Filtros e paginação.
- Ficha individual (atleta.php) com performance, inscrições, lesões,
  assiduidade e jogos.

Equipas
- Nome interno e designação oficial/comercial.
- Cidade, pavilhão, website e fonte.
- Ficha de equipa com plantel, equipa técnica, jogos e agenda.

Escalões / Inscrições
- Tabela própria de escalões.
- Relação atleta-equipa-escalão por época.
- Um atleta pode estar em mais do que uma equipa/escalão.

Competições
- Código FPA, género, formato, datas e fonte oficial.
- Relação competição-equipa.
- Tabela de classificação de referência.
- Ficha individual da competição.

Jogos
- Resultado, jornada, local, competição e observações.
- Página individual do jogo.
- Convocatória e titulares.
- Linha temporal de eventos.
- Estatísticas automáticas por atleta.

Eventos / Performance
- Golo
- Remate
- Remate falhado
- Remate defendido
- Remate ao poste
- Golo 7m
- 7m falhado
- 7m defendido
- Assistência
- Recuperação
- Perda de bola
- Falta
- Exclusão 2 min
- Cartões
- Defesa
- Defesa 7m
- Golo sofrido

IMPORTANTE: uma finalização deve ser registada uma única vez pelo seu
resultado. Ex.: se terminou em golo, regista "Golo" e não "Remate" + "Golo".

Relatórios
- Plantel completo CSV.
- Equipas CSV.
- Jogos/resultados CSV.
- Presenças CSV.
- Lesões CSV.

Pesquisa global
- Atletas por nome/CIPA.
- Equipas.
- Jogos/adversários.
- Competições.

PERMISSÕES
----------
Administrador:
- acesso total, incluindo utilizadores.

Treinador / Dirigente:
- consulta e gestão das áreas desportivas.
- sem gestão de utilizadores/sistema sensível.

Atleta:
- acesso de consulta.

FONTES EXTERNAS
---------------
A versão inclui referências à Federação de Andebol de Portugal e ao
zerozero Andebol para validação de nomes de competições, regulamentos,
calendários e informação pública.

O sistema NÃO faz scraping automático em cada abertura de página.
Isto evita que uma alteração num site externo parta a aplicação e mantém
a base local sob controlo do utilizador.

Foram preparadas referências para a época 2026/2027, incluindo:
- Campeonato Betclic Masculino;
- Campeonato Betclic Feminino;
- Taça de Portugal Betclic Masculina;
- Taça de Portugal Betclic Feminina;
- Campeonatos nacionais de formação;
- mapa de idades por escalão.

FICHEIROS IMPORTANTES
---------------------
database.sql
  Instalação completa de raiz, com dados consolidados.

upgrade_2026_2027.sql
  Atualização sem apagar a base existente.

config/config.php
  Ligação à base de dados e configuração principal.

includes/entities.php
  Definição central dos CRUDs.

includes/layout.php
  Navegação e layout global.

assets/css/style.css
  Interface completa.

assets/js/app.js
  Interações e gráficos.

SEGURANÇA
---------
- passwords com password_hash/password_verify;
- sessões PHP;
- regeneração do ID de sessão no login;
- proteção CSRF nos formulários;
- prepared statements nas operações parametrizadas;
- permissões por perfil;
- escape HTML com htmlspecialchars;
- auditoria de alterações quando disponível.

NOTA SOBRE DADOS DEMO
---------------------
No ficheiro database.sql, os dados físicos do CJ Almeida Garrett vindos do
ficheiro original continuam identificados como dados de demonstração e podem
ser desligados no início do SQL com:

SET @INCLUIR_DADOS_FISICOS_DEMO := 0;

O registo legacy "ssfs" continua desligado por defeito.
