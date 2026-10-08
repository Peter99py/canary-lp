# level-stats-fixer

Plugin do MyAAC que, ao alterar o **level** (ou a **vocação**) de um personagem no
editor de players do admin, recalcula de forma **absoluta** vida máxima, mana
máxima e cap — para o personagem não ficar "desfalcado".

## Por que existe

O servidor Canary **não recalcula** esses valores no login: ele lê `healthmax`,
`manamax` e `cap` direto do banco (`src/io/functions/iologindata_load_player.cpp`).
Como o level-up só soma um ganho fixo por nível (`addExperience`), mudar apenas o
level pelo admin deixaria o personagem com os valores antigos.

## Fórmula (canary)

```
base (nível 1): hp 150  mana 55  cap 400
ganho por nível L (a partir de L=2):
  se (vocação != None && L <= 8) -> ganhos da vocação None (5/5/10)   [rook]
  senão                          -> ganhos da vocação
healthmax(level) = 150 + Σ ganhos hp
manamax(level)   = 55  + Σ ganhos mana
cap(level)       = 400 + Σ ganhos cap
```

- Base derivada das amostras em `canary/schema.sql`.
- Ganhos iguais a `canary/data/XML/vocations.xml` (`gainhp`/`gainmana`/`gaincap`).

## Como funciona

- `level-stats-fixer.json` registra um hook `ADMIN_BEFORE_PAGE`.
- `hook.php` roda **antes** de `admin/pages/players.php`, e quando o save muda
  level/vocação reescreve `$_POST` (`health_max`, `health`, `mana_max`, `mana`,
  `capacity`). O core do MyAAC **não** é sobrescrito.
- `settings.php` expõe os valores usados em **Admin → Settings** como campos
  somente leitura (consulta).

## Comportamento

- Só age quando `level` ou `vocation` mudam (edições de skill, etc. não afetam).
- Absoluto: corrige também personagens que já estavam desfalcados.
- Aumenta e reduz (subtrai).
- `fill` (Admin → Settings) controla se vida/mana atuais vão para o máximo.

## Instalação

Já vem embutido na imagem (ver `canary/docker/quickstart/myaac/Dockerfile`).
Para ativar nesta instância:

1. Admin → **Plugins** (o plugin deve aparecer habilitado).
2. Limpe o cache do MyAAC (o registro de hooks é cacheado por ~10 min) ou
   reinicie o container.
