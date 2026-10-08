# Orientação específica do Canary

As políticas globais de Git, commit, PR, cabeçalho C++, exceção e documentação se aplicam. Este arquivo registra apenas os portões específicos do Canary.

## Recurring Defect Prevention

- Para um defeito reutilizável, inspecione caminhos análogos por comportamento e propriedade, corrija irmãos confirmados de forma atômica e mantenha a auditoria proporcional; não transforme um caso isolado em uma refatoração especulativa.
- Decida se o ferramental torna a recorrência impossível. Se não, adicione uma regra estreita ao `AGENTS.md` mais próximo que declare o padrão inseguro, a alternativa exigida e a validação, em vez de histórico de incidentes.
- Prefira salvaguardas aplicáveis — tipos, auxiliares, verificações estáticas, documentos de arquitetura ou testes focados — especialmente para vida útil, aritmética, identidade, propriedade, limites e escapes de cancelamento.

## Deferred Callback Lifetime Safety

- Assuma que callbacks agendados, adiados, de timer e de worker podem sobreviver ao seu objeto ou estado de origem.
- Nunca capture `this` bruto, referências, iteradores ou ponteiros de contêineres mutáveis através dessa fronteira. Use valores imutáveis mais `std::weak_ptr` ou identidade re-resolvível validada com a identidade original, geração, época ou token de sessão.
- Remoção, substituição, recarga ou reinterpretação devem cancelar trabalho pendente ou avançar uma geração verificada. Tipos que possuem callbacks são não-movíveis, a menos que a movimentação cancele ou rebind com segurança cada evento.
- Use aritmética limitada para intervalos; trabalho obsoleto deve se tornar um no-op antes de gameplay, Lua, combate, movimento, persistência ou saída para o cliente. Cubra proprietários destruídos/substituídos, IDs reutilizados, desligamento e transferência de propriedade quando prático.

## Static Ownership Lifetime Safety

- Nunca confie na ordem de destruição estática entre unidades de tradução para caches, registros ou outros proprietários globais de objetos de gameplay. Prefira estado de propriedade em tempo de execução; quando a propriedade global for inevitável, forneça um dreno idempotente explícito durante o desligamento controlado.
- Pare e junte cada produtor e consumidor antes de drenar a propriedade global, e libere objetos retidos enquanto todos os serviços que seus destrutores podem acessar ainda estão vivos. Valide o desligamento com entradas retidas e instrumentação de vida útil quando prático.

## Canary build discipline

- Antes de uma build local autorizada, leia `docs/building/local-validation.md`; seu ponto de entrada mantido, ambiente, preset, cache e fluxo de trabalho MSVC Ninja são obrigatórios.
- Adições, remoções e renomeações de fontes/cabeçalhos C++ devem atualizar cada entrada mantida: a lista CMake relevante, `server vcproj/canary.vcxproj` e a lista CMake de testes quando aplicável.

### MSVC Ninja dependency tracking

- Antes de configurar, reparar ou auditar uma build MSVC Ninja, leia `docs/building/local-validation.md#msvc-ninja-dependency-tracking`. Suas regras de página de código, lançador, log de dependência e concorrência permanecem obrigatórias.

## Precompiled Header Policy

- `src/pch.hpp` possui inclusões padrão compartilhadas amplas; não duplique uma inclusão PCH não protegida.
- Cabeçalhos devem declarar suas dependências públicas. Quando uma fonte precisa de uma inclusão fornecida pelo PCH sem PCH, proteja com `#ifndef USE_PRECOMPILED_HEADERS`; adicione inclusões amplas ao PCH com o mesmo fallback local.

## Lua Shared Userdata Gate

- Antes de alterar userdata Lua `std::shared_ptr`, leia `docs/systems/lua-shared-userdata.md` e use seus auxiliares de registro, traço tipado e push.
- Nunca combine `pushUserdata` compartilhado com uma metatable manual, use uma metatable fraca para userdata compartilhado, ou envolva um objeto emprestado sem um deleter no-op. Execute as duas verificações `rg` do documento e investigue cada correspondência.

## Docker Quickstart Policy

- Para mudanças no quickstart, leia `docs/docker/quickstart-for-beginners.md` e `docker/DOCKER.md`; mantenha as responsabilidades de CI/build, desenvolvimento e quickstart do usuário separadas.
- O caminho padrão do cliente é `login-server` em `http://localhost:8088/login`, nunca `login.php` do MyAAC. O MyAAC permanece apenas para site/admin, usa `slawkens/myaac` `develop` e mantém `http://localhost:8080`; a configuração pública permanece `CANARY_*`, e o quickstart usa a imagem de runtime publicada do Canary.

## Regras para Agentes de IA

### Modo Passivo (Padrão)

- O modo passivo é o padrão obrigatório (`default`).
- No modo passivo, o agente de IA deve apenas ler, compreender e sugerir.
- Nenhuma execução, modificação, remoção, build, commit, PR, ou alteração de arquivo deve ser realizada sem ordem explícita.
- Se o prompt não contiver comando para execução, o agente deve permanecer no modo passivo.

### Modo Ativo

- O modo ativo só é ativado quando há ordem explícita de execução ou ativação do modo no prompt.
- Exemplos de ativação: "execute", "remova", "edite", "faça a build", "confirme", "ative o modo ativo".
- No modo ativo, o agente pode executar ações conforme solicitado, mas ainda deve confirmar antes de operações destrutivas quando apropriado.

### Regra Geral

- Se no prompt não houver comando para execução, o agente de IA deve rodar no modo passivo.
- A transição para modo ativo requer instrução explícita e inequívoca no prompt do usuário.

## Regras de Formatação de Documentos `.md`

- Não crie `.md` com formatações exageradas; use apenas o básico necessário para leitura humana.
- Evite tabelas complexas, listas aninhadas profundas, blocos de código extensos sem necessidade, emojis, cores, ou formatações decorativas.
- Priorize texto simples, títulos curtos (`#`, `##`), listas simples (`-` ou `*`) e parágrafos curtos.
- O objetivo é economia de token e contexto ao utilizar agentes de IA; mantenha o documento direto e legível.

## Mapeamento do Projeto

`src/`
- `game/` → engine/core, dispatcher, scheduler, budget, policy
- `creatures/` → player, monster, npc, definitions
- `server/network/` → protocol/login, protocol/game
- `lua/` → bindings, enums, modal_window, docgen
- `items/` → item definitions
- `map/` → world, map management
- `io/` → persistence, database access
- `utils/` → definitions, helpers

`data/`
- `scripts/` → gameplay Lua (quests, spells, actions, movements)
- `libs/` → shared Lua libraries (tables, functions)
- `canary/` → minimal datapack
- `otservbr-global/` → full global datapack

`docs/`
- `systems/` → architecture docs per module (taskboard, livestream, multiprotocol, performance)
- `building/` → build/CI docs (local-validation, windows-cmake, recompile)
- `lua-api/` → generated Lua API docs
- `docker/` → quickstart docs
