-- minerar: cava um tunel 3x3 e volta pro bau quando enche.
-- Uso: minerar <comprimento>

local LIXO = {}
local COMBUSTIVEL = {
  ["minecraft:coal"] = true, ["minecraft:charcoal"] = true, ["minecraft:coal_block"] = true,
  ["minecraft:dried_kelp_block"] = true, ["minecraft:lava_bucket"] = true, ["minecraft:blaze_rod"] = true,
}

local minerios_encontrados = {}

local function ehMinerio(nome)
  return nome and (nome:find("_ore") or nome:find("ancient_debris"))
end

local function registrarMinerio(rx, ry, rz, nome)
  for _, m in ipairs(minerios_encontrados) do
    if m.x == rx and m.y == ry and m.z == rz then return end
  end
  table.insert(minerios_encontrados, {x = rx, y = ry, z = rz, nome = nome})
end

local comprimento = tonumber(({ ... })[1])
if not comprimento or comprimento < 1 then
  print("Uso: minerar <comprimento>")
  return
end

local x, y, z, f = 0, 0, 0, 0
local zMax = 0

local function comb()
  local n = turtle.getFuelLevel()
  return n == "unlimited" and math.huge or n
end

local function direita() turtle.turnRight(); f = (f + 1) % 4 end
local function esquerda() turtle.turnLeft(); f = (f + 3) % 4 end
local function virarPara(alvo)
  local d = (alvo - f) % 4
  if d == 1 then direita() elseif d == 2 then direita(); direita() elseif d == 3 then esquerda() end
end

local function pegarLava(inspecionar, colocar)
  local ok, b = inspecionar()
  if not ok or b.name ~= "minecraft:lava" or b.state.level ~= 0 then return end
  local limite = turtle.getFuelLimit()
  if limite == "unlimited" or comb() + 1000 > limite then return end
  for s = 1, 16 do
    local it = turtle.getItemDetail(s)
    if it and it.name == "minecraft:bucket" then
      turtle.select(s); colocar(); break
    end
  end
  for s = 1, 16 do
    local it = turtle.getItemDetail(s)
    if it and it.name == "minecraft:lava_bucket" then turtle.select(s); turtle.refuel() end
  end
  turtle.select(1)
end

local function cai(b) return b.name:find("gravel") or b.name:find("sand") or b.name:find("powder") end

local function mover(mv, cavar, atacar)
  for _ = 1, 60 do
    if mv() then return true end
    if comb() == 0 then error("Sem combustivel", 0) end
    local ok, motivo = cavar()
    if not ok then
      if motivo and (motivo:find("nbreakable") or motivo:find("rotect")) then
        error("Bloco inquebravel ou protegido no caminho", 0)
      end
      atacar()
    end
  end
  return false
end

local function subir_seguro()
  if y >= 2 then return false end
  local ok, b = turtle.inspectUp()
  if ok and ehMinerio(b.name) then registrarMinerio(x, y+1, z, b.name); return false end
  pegarLava(turtle.inspectUp, turtle.placeUp)
  if mover(turtle.up, turtle.digUp, turtle.attackUp) then
    y = y + 1; return true
  end
  return false
end

local function descer_seguro()
  if y <= 0 then return false end
  local ok, b = turtle.inspectDown()
  if ok and ehMinerio(b.name) then registrarMinerio(x, y-1, z, b.name); return false end
  pegarLava(turtle.inspectDown, turtle.placeDown)
  if mover(turtle.down, turtle.digDown, turtle.attackDown) then
    y = y - 1; return true
  end
  return false
end

local function frente_seguro()
  local ok, b = turtle.inspect()
  if ok and ehMinerio(b.name) then
    local tx = x + (f == 1 and 1 or (f == 3 and -1 or 0))
    local tz = z + (f == 0 and 1 or (f == 2 and -1 or 0))
    registrarMinerio(tx, y, tz, b.name)
    return false
  end
  pegarLava(turtle.inspect, turtle.place)
  if mover(turtle.forward, turtle.dig, turtle.attack) then
    if f == 0 then z = z + 1 elseif f == 1 then x = x + 1 elseif f == 2 then z = z - 1 else x = x - 1 end
    return true
  end
  return false
end

local function avancar()
  if frente_seguro() then return true end
  
  local startY = y
  while subir_seguro() do if frente_seguro() then return true end end
  while y > startY do descer_seguro() end
  
  while descer_seguro() do if frente_seguro() then return true end end
  while y < startY do subir_seguro() end
  
  return false
end

local function recentralizarY()
  while y < 1 do if not subir_seguro() then break end end
  while y > 1 do if not descer_seguro() then break end end
end

local function limparColuna()
  -- Limita a quebra para cima apenas se NÃO estiver no teto do túnel (y=2)
  if y < 2 then
    local okU, bU = turtle.inspectUp()
    if okU then
      if ehMinerio(bU.name) then 
        registrarMinerio(x, y+1, z, bU.name)
      else 
        pegarLava(turtle.inspectUp, turtle.placeUp)
        while okU do
          if ehMinerio(bU.name) then registrarMinerio(x, y+1, z, bU.name); break end
          if not turtle.digUp() then break end
          if cai(bU) then sleep(0.4) end
          okU, bU = turtle.inspectUp()
        end
      end
    end
  end
  
  -- Limita a quebra para baixo apenas se NÃO estiver no chão do túnel (y=0)
  if y > 0 then
    local okD, bD = turtle.inspectDown()
    if okD then
      if ehMinerio(bD.name) then registrarMinerio(x, y-1, z, bD.name)
      else
        pegarLava(turtle.inspectDown, turtle.placeDown)
        turtle.digDown()
      end
    end
  end
end

local function abastecer(minimo)
  for s = 1, 16 do
    if comb() >= minimo then break end
    local it = turtle.getItemDetail(s)
    if it and COMBUSTIVEL[it.name] then
      turtle.select(s)
      while comb() < minimo and turtle.refuel(1) do end
    end
  end
  turtle.select(1)
  return comb() >= minimo
end

local function esperarCombustivel(minimo)
  while not abastecer(minimo) do
    print(("Preciso de %d combustivel."):format(minimo))
    os.pullEvent("turtle_inventory")
  end
end

local function vazios()
  local n = 0
  for s = 1, 16 do if turtle.getItemCount(s) == 0 then n = n + 1 end end
  return n
end

local function irCasa()
  if x ~= 0 then
    virarPara(x > 0 and 3 or 1)
    while x ~= 0 do avancar() end
  end
  
  virarPara(2)
  while z > 0 do
    if not avancar() then
      direita()
      if avancar() then
        esquerda(); avancar()
        esquerda(); avancar()
        direita()
      else
        esquerda(); esquerda()
        if avancar() then
          direita(); avancar()
          direita(); avancar()
          esquerda()
        else
           print("Preso ao voltar pra casa!")
           break
        end
      end
    end
  end
  
  while y > 0 do descer_seguro() end
  virarPara(2)
end

local function voltarAoTrabalho(sx, sy, sz, sf)
  virarPara(0)
  if sz >= 1 then avancar() end
  if sx ~= 0 then virarPara(sx > 0 and 1 or 3); while x ~= sx do avancar() end end
  virarPara(0)
  while z < sz do avancar() end
  while y < sy do subir_seguro() end
  virarPara(sf)
end

local function guardarNoBau()
  local reserva = false
  for s = 1, 16 do
    local it = turtle.getItemDetail(s)
    if it then
      local manter = it.name == "minecraft:bucket"
      if not reserva and COMBUSTIVEL[it.name] and it.name ~= "minecraft:lava_bucket" then
        reserva, manter = true, true
      end
      if not manter then
        turtle.select(s)
        while not turtle.drop() do sleep(5) end
      end
    end
  end
  turtle.select(1)
end

local function jogarLixo()
  for s = 1, 16 do
    local it = turtle.getItemDetail(s)
    if it and LIXO[it.name] then turtle.select(s); turtle.dropDown() end
  end
  turtle.select(1)
end

local function viagemAoBau(motivo)
  local sx, sy, sz, sf = x, y, z, f
  print(motivo .. " Voltando pro bau...")
  irCasa()
  guardarNoBau()
  esperarCombustivel(2 * (math.abs(sx) + sz + 1) + 20)
  voltarAoTrabalho(sx, sy, sz, sf)
end

local function fatia()
  recentralizarY()
  limparColuna()
  local caminho = x == 0 and { 1, -1 } or { -x }
  for _, alvo in ipairs(caminho) do
    virarPara(alvo > x and 1 or 3)
    while x ~= alvo do
      if not avancar() then break end
      recentralizarY() -- Força a descida/subida caso tenha desviado
      limparColuna()
    end
  end
  virarPara(0)
  
  if x ~= 0 then
    virarPara(x > 0 and 3 or 1)
    while x ~= 0 do 
      avancar() 
      recentralizarY() -- Mantém alinhamento no retorno ao centro
    end
    virarPara(0)
  end
end

local function trabalho()
  esperarCombustivel(20)
  subir_seguro()
  
  for i = 1, comprimento do
    if not abastecer(math.abs(x) + y + z + 12) then viagemAoBau("Combustivel baixo.") end
    
    recentralizarY()
    if not avancar() then
      print("Parede intransponivel de minerios! Abortando avanço.")
      break
    end
    
    zMax = math.max(zMax, z)
    fatia()
    if vazios() <= 1 then
      jogarLixo()
      if vazios() <= 1 then viagemAoBau("Inventario cheio.") end
    end
    if i % 10 == 0 then print(("Fatia %d/%d"):format(i, comprimento)) end
  end
end

virarPara(2)
local temBau, bloco = turtle.inspect()
virarPara(0)
if not (temBau and (bloco.name:find("chest") or bloco.name:find("barrel") or bloco.name:find("shulker"))) then 
  return print("Coloque um bau ATRAS da tartaruga.") 
end

print(("Minerando tunel de %d blocos..."):format(comprimento))
local ok, erro = pcall(trabalho)
if not ok then print("Parei: " .. tostring(erro)) end
irCasa(); guardarNoBau(); virarPara(0)

if #minerios_encontrados > 0 then
  local f_out = io.open("minerios.txt", "w")
  if f_out then
    for _, m in ipairs(minerios_encontrados) do
      f_out:write(("%s em x:%d, y:%d, z:%d\n"):format(m.nome:gsub("minecraft:", ""), m.x, m.y, m.z))
    end
    f_out:close()
    print(("Deixei %d minerios para tras! Salvo em minerios.txt"):format(#minerios_encontrados))
  end
end