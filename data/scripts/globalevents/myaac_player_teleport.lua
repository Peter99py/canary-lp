-- myaac_player_teleport.lua
--
-- Processa pedidos de teletransporte disparados pelo painel admin do MyAAC.
--
-- O servidor e a fonte de verdade: o site nao decide online/offline (a tabela
-- players_online e reconstruida pelo servidor a cada 10 min e fica defasada).
-- O site apenas enfileira o pedido como 'pending'; aqui resolvemos em tempo
-- real, igual ao comando /pos:
--   - jogador online: valida o tile e teletransporta agora (com efeito);
--   - jogador offline: grava players.posx/posy/posz para o proximo login.
--
-- Cada pedido e reivindicado de forma atomica ('pending' -> 'processing') para
-- impedir duplo teleporte/reprocessamento.

local TELEPORT_TABLE = "myaac_teleport_queue"
local POLL_INTERVAL_MS = 1000
local MAX_ROWS = 10
-- Pedido 'processing' mais antigo que isso e considerado abandonado (ex.: crash
-- no meio do processamento) e pode ser reivindicado novamente.
local PROCESSING_LEASE_SECONDS = 60

local tableReady = false
local function isQueueReady()
	if tableReady then
		return true
	end
	tableReady = db.tableExists(TELEPORT_TABLE)
	return tableReady
end

local function markRequest(id, status, message)
	db.query(string.format(
		"UPDATE `%s` SET `status` = %s, `message` = %s, `processed_at` = %d WHERE `id` = %d",
		TELEPORT_TABLE,
		db.escapeString(status),
		db.escapeString(message or ""),
		os.time(),
		id
	))
end

-- Move 'pending' -> 'processing' de forma atomica. A API Lua do servidor nao
-- expoe "linhas afetadas", entao usamos um token unico em `message` e
-- confirmamos a posse por SELECT: se o token nao voltar, outro consumidor (ou
-- uma execucao anterior) ficou com o pedido e devemos ignora-lo.
local function claimRequest(id)
	local now = os.time()
	local token = string.format("%d-%d-%d", now, id, math.random(1, 2147483647))

	db.query(string.format(
		"UPDATE `%s` SET `status` = 'processing', `message` = %s, `processed_at` = %d "
			.. "WHERE `id` = %d AND (`status` = 'pending' OR (`status` = 'processing' AND `processed_at` < %d))",
		TELEPORT_TABLE,
		db.escapeString(token),
		now,
		id,
		now - PROCESSING_LEASE_SECONDS
	))

	local checked = db.storeQuery(string.format(
		"SELECT `id` FROM `%s` WHERE `id` = %d AND `status` = 'processing' AND `message` = %s LIMIT 1",
		TELEPORT_TABLE,
		id,
		db.escapeString(token)
	))
	if not checked then
		return false
	end

	result.free(checked)
	return true
end

-- Ids de pedidos pendentes e de pedidos 'processing' com lease expirada.
local function fetchPendingIds()
	local resultId = db.storeQuery(string.format(
		"SELECT `id` FROM `%s` WHERE `status` = 'pending' "
			.. "OR (`status` = 'processing' AND `processed_at` < %d) ORDER BY `id` ASC LIMIT %d",
		TELEPORT_TABLE,
		os.time() - PROCESSING_LEASE_SECONDS,
		MAX_ROWS
	))
	if not resultId then
		return {}
	end

	local ids = {}
	repeat
		ids[#ids + 1] = result.getNumber(resultId, "id")
	until not result.next(resultId)
	result.free(resultId)

	return ids
end

local function loadRequest(id)
	local resultId = db.storeQuery(string.format(
		"SELECT `player_id`, `pos_x`, `pos_y`, `pos_z` FROM `%s` WHERE `id` = %d LIMIT 1",
		TELEPORT_TABLE,
		id
	))
	if not resultId then
		return nil
	end

	local row = {
		player_id = result.getNumber(resultId, "player_id"),
		pos_x = result.getNumber(resultId, "pos_x"),
		pos_y = result.getNumber(resultId, "pos_y"),
		pos_z = result.getNumber(resultId, "pos_z"),
	}
	result.free(resultId)

	return row
end

-- Aplica um pedido que ja foi reivindicado por este consumidor.
local function processRequest(id)
	local row = loadRequest(id)
	if not row then
		markRequest(id, "failed", "Pedido inexistente")
		return
	end

	local playerId = row.player_id
	local posX, posY, posZ = row.pos_x, row.pos_y, row.pos_z
	local targetPos = Position(posX, posY, posZ)
	local player = Player(playerId)

	if player then
		-- Jogador online: mesma validacao e efeito do comando /pos.
		if not Tile(targetPos) then
			markRequest(id, "failed", "Destino invalido: tile inexistente (" .. posX .. ", " .. posY .. ", " .. posZ .. ")")
			return
		end

		player:teleportTo(targetPos)
		if not player:isInGhostMode() then
			targetPos:sendMagicEffect(CONST_ME_TELEPORT)
		end
		player:sendTextMessage(MESSAGE_EVENT_ADVANCE, "You have been teleported by an administrator.")
		markRequest(id, "done", "Teleportado online com sucesso")
	else
		-- Jogador offline: grava a posicao de login para o proximo acesso.
		db.query(string.format(
			"UPDATE `players` SET `posx` = %d, `posy` = %d, `posz` = %d WHERE `id` = %d",
			posX, posY, posZ, playerId
		))
		markRequest(id, "done", "Jogador offline: coordenadas salvas no banco")
	end
end

local teleportDispatcher = GlobalEvent("MyAACTeleportDispatcher")

function teleportDispatcher.onThink(interval)
	if not isQueueReady() then
		return true
	end

	for _, id in ipairs(fetchPendingIds()) do
		if claimRequest(id) then
			processRequest(id)
		end
	end

	return true
end

teleportDispatcher:interval(POLL_INTERVAL_MS)
teleportDispatcher:register()
