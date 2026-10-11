-- myaac_item_delivery
--
-- Entrega itens pedidos pelo site (plugin "give-item" do MyAAC).
--
-- O MyAAC grava pedidos na tabela `myaac_item_delivery` (mesmo banco do
-- servidor) com status 'pending'. Este script entrega para o personagem:
--   - online: no proximo tick do GlobalEvent;
--   - offline: no proximo login (CreatureEvent onLogin), quando ele entra.
--
-- A entrega usa o mesmo caminho do comando /i (player:addItem): o item vai para
-- a backpack/containers do personagem, criando uma Backpack (2854) se ele nao
-- tiver nenhuma. Itens que nao couberem NAO caem no chao (canDropOnMap = false):
-- o pedido fica com status 'failed' e o motivo em `message`.

local DELIVERY_TABLE = "myaac_item_delivery"
local POLL_INTERVAL_MS = 5000
local MAX_ROWS = 100
local BACKPACK_ID = 2854

-- A tabela e criada pelo MyAAC na primeira vez que o painel do plugin abre.
-- Checamos a existencia de forma tardia (uma vez) para nao poluir o log com
-- queries em uma tabela que talvez ainda nao exista no primeiro boot.
local tableReady = false

local function deliveryReady()
	if tableReady then
		return true
	end
	tableReady = db.tableExists(DELIVERY_TABLE)
	return tableReady
end

local function mark(id, status, message)
	db.query(string.format(
		"UPDATE `%s` SET `status` = %s, `message` = %s, `processed_at` = %d WHERE `id` = %d",
		DELIVERY_TABLE,
		db.escapeString(status),
		db.escapeString(message or ""),
		os.time(),
		id
	))
end

local function deliverRow(player, row)
	if ItemType(row.item_id):getId() == 0 then
		mark(row.id, "failed", "item inexistente (id " .. row.item_id .. ")")
		return
	end

	if row.target == "store_inbox" then
		local item = player:addItemStoreInbox(row.item_id, row.count, true, false)
		if item then
			mark(row.id, "done", "")
		else
			mark(row.id, "failed", "nao foi possivel entregar na store inbox")
		end
		return
	end

	-- Destino padrao: backpack/containers. Cria uma Backpack se o personagem
	-- nao tiver nenhuma (mesmo comportamento do comando /i).
	if not player:getSlotItem(CONST_SLOT_BACKPACK) then
		player:addItem(BACKPACK_ID, 1, false, 1, CONST_SLOT_BACKPACK)
	end

	local item = player:addItem(row.item_id, row.count, false, 1, CONST_SLOT_WHEREEVER, row.tier)
	if item then
		mark(row.id, "done", "")
	else
		mark(row.id, "failed", "sem espaco (inventario/backpack cheios)")
	end
end

-- Entrega todos os pedidos pendentes de um personagem online.
local function processPlayer(player)
	local resultId = db.storeQuery(string.format(
		"SELECT `id`, `item_id`, `count`, `tier`, `target` FROM `%s` "
			.. "WHERE `player_id` = %d AND `status` = 'pending' ORDER BY `id` ASC LIMIT %d",
		DELIVERY_TABLE,
		player:getGuid(),
		MAX_ROWS
	))
	if not resultId then
		return
	end

	local rows = {}
	repeat
		rows[#rows + 1] = {
			id = Result.getNumber(resultId, "id"),
			item_id = Result.getNumber(resultId, "item_id"),
			count = Result.getNumber(resultId, "count"),
			tier = Result.getNumber(resultId, "tier"),
			target = Result.getString(resultId, "target"),
		}
	until not Result.next(resultId)
	Result.free(resultId)

	for _, row in ipairs(rows) do
		-- Re-confere o dono: o personagem pode ter passado por relog/replace.
		local current = Player(player:getGuid())
		if not current then
			return
		end
		deliverRow(current, row)
	end
end

local deliveryThink = GlobalEvent("MyAacItemDelivery")

function deliveryThink.onThink(interval)
	if not deliveryReady() then
		return true
	end

	local resultId = db.storeQuery(string.format(
		"SELECT DISTINCT `player_id` FROM `%s` WHERE `status` = 'pending' LIMIT %d",
		DELIVERY_TABLE,
		MAX_ROWS
	))
	if not resultId then
		return true
	end

	local playerIds = {}
	repeat
		playerIds[#playerIds + 1] = Result.getNumber(resultId, "player_id")
	until not Result.next(resultId)
	Result.free(resultId)

	for _, playerId in ipairs(playerIds) do
		local player = Player(playerId)
		if player then
			processPlayer(player)
		end
	end

	return true
end

deliveryThink:interval(POLL_INTERVAL_MS)
deliveryThink:register()

local deliveryLogin = CreatureEvent("MyAacItemDeliveryLogin")

function deliveryLogin.onLogin(player)
	if not player or not deliveryReady() then
		return true
	end

	processPlayer(player)
	return true
end

deliveryLogin:register()
