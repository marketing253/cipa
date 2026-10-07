# Urna CIPA 2026 — Auto Viação Redentor

Urna eletrônica da eleição da CIPA (NR-5). A tela é `app/urna.html` (um arquivo só);
o servidor é PHP 8.2 + SQLite (`app/lib.php`, chamado por `public/api.php`).

| Endereço | Quem usa |
|---|---|
| `/` | colaborador vota (matrícula → confirma nome → cédula → CONFIRMA) |
| `/?p=comissao` | comissão eleitoral (candidatos, lista de colaboradores, abrir/encerrar, apuração, ata) |
| `/?p=inscricao` | colaborador se inscreve como candidato pelo celular |
| `/?p=totem` | tablet fixo: toque para iniciar em tela cheia, tela sempre ligada, volta à matrícula após 60 s sem uso (a tela FIM espera o CONCLUIR) |

## Deploy no EasyPanel

- Origem: GitHub `marketing253/cipa`, branch `main`, build por **Dockerfile**.
- **Volume obrigatório** montado em `/data` — é onde ficam o banco, os eleitores e os VOTOS.
- Variáveis de ambiente:
  - `CIPA_ADMIN_SENHA` — senha inicial do usuário `admin` (mín. 6). Depois de trocar a senha pela tela, ela passa a valer e esta variável é ignorada.
  - `CIPA_PERMITIR_ZERAR=1` — só para teste: mostra o botão que volta a urna para preparação apagando os votos. **Remover antes da eleição oficial.**
- Porta interna: 80.

## Sigilo e auditoria

- Tabela `votos` guarda só `{id aleatório, número}` (WITHOUT ROWID): sem matrícula, sem hora, sem ordem de chegada.
- Encerramento grava o hash SHA-256 da urna; a apuração confere votos na urna × lista de votantes.
- Apuração: mais votados; empate → admissão mais antiga; participação < 50% avisa para prorrogar (NR-5).

## Testes

```
php testes/teste.php
```
