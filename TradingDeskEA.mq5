//+------------------------------------------------------------------+
//| Trading Desk EA                                                  |
//+------------------------------------------------------------------+
#property copyright "Trading Desk"
#property version   "1.00"

input string InpApiKey  = "";                       // API Key
input string InpBaseURL = "http://127.0.0.1:8080";  // Server Base URL

#define MAX_INSTRUMENTS     50
#define TIMER_MS            500

struct InstrumentInfo
{
   string app_symbol;
   int    price_points;
};

bool           g_authenticated    = false;
InstrumentInfo g_instruments[MAX_INSTRUMENTS];
int            g_instrument_count = 0;

// Full broker symbol map (all Market Watch symbols)
#define MAX_BROKER_SYMBOLS 500
string g_broker_symbols[MAX_BROKER_SYMBOLS];
int    g_broker_map_idx[MAX_BROKER_SYMBOLS]; // index into g_instruments[], -1 = not matched
int    g_broker_symbol_count = 0;

// Universal settings (loaded from server)
int    g_max_trades            = 100;
double g_good_price_expansion  = 20.0;

// Good price series tracker
#define MAX_SERIES 50
struct GoodPriceSeries
{
   string broker_sym;
   string direction;        // "buy" or "sell"
   double last_entry_price;
   int    trade_count;      // total trades fired (including initial)
   bool   active;
};
GoodPriceSeries g_series[MAX_SERIES];
int             g_series_count = 0;

// Delayed multi-trade queue (for num_trades > 1, each entry fires 60s apart)
#define MAX_DELAYED 50
struct DelayedTrade
{
   string   broker_sym;
   string   direction;
   double   lot;
   int      remaining;   // trades still to fire
   datetime next_fire;  // fire when TimeCurrent() >= this
};
DelayedTrade g_delayed[MAX_DELAYED];
int          g_delayed_count      = 0;
datetime     g_last_stats_push    = 0;
datetime     g_last_settings_load = 0;

// Common broker alias → app symbol (for completely different names)
string g_alias_from[] = { "GOLD", "SILVER", "OIL", "WTI", "BRENT", "CRUDE", "NATGAS", "NGAS" };
string g_alias_to[]   = { "XAUUSD", "XAGUSD", "USOIL", "USOIL", "UKOIL", "USOIL", "NATGAS", "NATGAS" };

//+------------------------------------------------------------------+
int OnInit()
{
   if (InpApiKey == "")
   {
      Alert("Trading Desk EA: API Key is empty.");
      return INIT_PARAMETERS_INCORRECT;
   }

   if (!Authenticate())
   {
      Alert("Trading Desk EA: Authentication failed.");
      return INIT_FAILED;
   }
   g_authenticated = true;
   Print("=== Trading Desk EA ===");
   Print("Authenticated OK  |  server: ", InpBaseURL);

   if (!LoadSettings())
   {
      Alert("Trading Desk EA: Failed to load settings.");
      return INIT_FAILED;
   }
   Print("Settings loaded   |  instruments: ", g_instrument_count);

   BuildSymbolMap();

   // Report symbol mapping results
   int matched = 0;
   for (int i = 0; i < g_broker_symbol_count; i++)
      if (g_broker_map_idx[i] >= 0) matched++;
   Print("Symbol map built  |  ", matched, "/", g_broker_symbol_count, " Market Watch symbols matched");
   for (int i = 0; i < g_broker_symbol_count; i++)
   {
      if (g_broker_map_idx[i] >= 0)
         Print("  ", g_broker_symbols[i], " -> ", g_instruments[g_broker_map_idx[i]].app_symbol,
               "  pp=", g_instruments[g_broker_map_idx[i]].price_points);
   }
   Print("Timer started     |  ", TIMER_MS, "ms interval");
   Print("=======================");

   EventSetMillisecondTimer(TIMER_MS);
   return INIT_SUCCEEDED;
}

//+------------------------------------------------------------------+
void OnDeinit(const int reason)
{
   EventKillTimer();
   g_authenticated = false;
}

//+------------------------------------------------------------------+
void OnTick()
{
   if (!g_authenticated) return;
   // Use GetInstrumentIdx(Symbol()) or GetPricePoints(Symbol()) for any broker symbol
   // Example: int pp = GetPricePoints(Symbol());
}

//+------------------------------------------------------------------+
void OnTimer()
{
   if (!g_authenticated) return;

   datetime now = TimeCurrent();

   // Reload settings every 30 s — not on every 500 ms tick
   if (now - g_last_settings_load >= 30)
   {
      if (LoadSettings())
         BuildSymbolMap();
      g_last_settings_load = now;
   }

   PollTrades();
   ProcessDelayedTrades();
   CheckGoodPriceSeries();
   PollManageCommands();

   // Push account stats every ~1 s
   if (now - g_last_stats_push >= 1)
   {
      PushStats();
      g_last_stats_push = now;
   }
}

//+------------------------------------------------------------------+
bool Authenticate()
{
   char   data[];
   char   result[];
   string result_headers;

   string url     = InpBaseURL + "/api/auth.php";
   string body    = "api_key=" + InpApiKey;
   string headers = "Content-Type: application/x-www-form-urlencoded\r\n";

   StringToCharArray(body, data, 0, StringLen(body));
   ResetLastError();
   int http_code = WebRequest("POST", url, headers, 5000, data, result, result_headers);

   if (http_code == -1)
   {
      Print("BLOCKED – Add to allowed URLs: ", InpBaseURL);
      return false;
   }
   if (http_code == 1001 || http_code == 1002 || http_code == 1003)
   {
      Print("CONNECTION FAILED – Is the PHP server running? Is ", InpBaseURL, " in the allowed URLs list?");
      return false;
   }
   if (http_code != 200) { Print("Auth HTTP ", http_code); return false; }

   return StringFind(CharArrayToString(result), "\"ok\":true") >= 0;
}

//+------------------------------------------------------------------+
bool LoadSettings()
{
   char   data[];
   char   result[];
   string result_headers;

   string url     = InpBaseURL + "/api/settings.php";
   string body    = "api_key=" + InpApiKey;
   string headers = "Content-Type: application/x-www-form-urlencoded\r\n";

   StringToCharArray(body, data, 0, StringLen(body));
   ResetLastError();
   int http_code = WebRequest("POST", url, headers, 3000, data, result, result_headers);

   if (http_code != 200) { Print("Settings HTTP ", http_code); return false; }

   string json = CharArrayToString(result);
   if (StringFind(json, "\"ok\":true") < 0) return false;

   ParseInstruments(json);

   // Parse universal settings
   int gpe_pos = StringFind(json, "\"good_price_expansion\":");
   if (gpe_pos >= 0)
   {
      int s = gpe_pos + 23, e = s;
      while (e < StringLen(json) && StringGetCharacter(json, e) >= '0' && StringGetCharacter(json, e) <= '9') e++;
      g_good_price_expansion = (double)StringToInteger(StringSubstr(json, s, e - s));
   }
   int mt_pos = StringFind(json, "\"max_trades\":");
   if (mt_pos >= 0)
   {
      int s = mt_pos + 13, e = s;
      while (e < StringLen(json) && StringGetCharacter(json, e) >= '0' && StringGetCharacter(json, e) <= '9') e++;
      g_max_trades = (int)StringToInteger(StringSubstr(json, s, e - s));
   }

   return true;
}

//+------------------------------------------------------------------+
// Minimal JSON parser for the instruments array
void ParseInstruments(const string &json)
{
   InstrumentInfo tmp[MAX_INSTRUMENTS];
   int new_count = 0;
   int json_len  = StringLen(json);
   int pos       = StringFind(json, "\"instruments\"");
   if (pos < 0) return;

   while (new_count < MAX_INSTRUMENTS)
   {
      int sym_pos = StringFind(json, "\"symbol\":\"", pos);
      if (sym_pos < 0) break;
      int sym_start = sym_pos + 10;
      int sym_end   = StringFind(json, "\"", sym_start);
      if (sym_end < 0) break;

      int pp_pos = StringFind(json, "\"price_points\":", sym_end);
      if (pp_pos < 0) break;
      int pp_start = pp_pos + 15;
      int pp_end   = pp_start;
      while (pp_end < json_len && StringGetCharacter(json, pp_end) >= '0' && StringGetCharacter(json, pp_end) <= '9')
         pp_end++;

      tmp[new_count].app_symbol   = StringSubstr(json, sym_start, sym_end - sym_start);
      tmp[new_count].price_points = (int)StringToInteger(StringSubstr(json, pp_start, pp_end - pp_start));
      new_count++;
      pos = pp_end;
   }

   g_instrument_count = new_count;
   for (int i = 0; i < new_count; i++)
      g_instruments[i] = tmp[i];
}

//+------------------------------------------------------------------+
// Build full map: every Market Watch symbol -> g_instruments[] index
void BuildSymbolMap()
{
   g_broker_symbol_count = 0;
   int total = SymbolsTotal(true); // Market Watch symbols only

   for (int i = 0; i < total && g_broker_symbol_count < MAX_BROKER_SYMBOLS; i++)
   {
      string sym = SymbolName(i, true);
      g_broker_symbols[g_broker_symbol_count] = sym;
      g_broker_map_idx[g_broker_symbol_count] = MapSymbol(sym);
      g_broker_symbol_count++;
   }

   int matched = 0;
   for (int i = 0; i < g_broker_symbol_count; i++)
      if (g_broker_map_idx[i] >= 0) matched++;
}

//+------------------------------------------------------------------+
// Lookup instrument index for any broker symbol (uses pre-built map, fallback to live resolve)
int GetInstrumentIdx(const string broker_sym)
{
   for (int i = 0; i < g_broker_symbol_count; i++)
      if (g_broker_symbols[i] == broker_sym) return g_broker_map_idx[i];
   return MapSymbol(broker_sym); // fallback for symbols added after init
}

//+------------------------------------------------------------------+
// Convenience: get price_points for a broker symbol, -1 if not matched
int GetPricePoints(const string broker_sym)
{
   int idx = GetInstrumentIdx(broker_sym);
   if (idx < 0) return -1;
   return g_instruments[idx].price_points;
}

//+------------------------------------------------------------------+
void PollTrades()
{
   char   data[];
   char   result[];
   string result_headers;

   string body    = "api_key=" + InpApiKey + "&action=poll";
   string headers = "Content-Type: application/x-www-form-urlencoded\r\n";
   StringToCharArray(body, data, 0, StringLen(body));

   int http_code = WebRequest("POST", InpBaseURL + "/api/trade.php",
                              headers, 3000, data, result, result_headers);
   if (http_code != 200) return;

   string json = CharArrayToString(result);
   if (StringFind(json, "\"ok\":true") < 0) return;

   // Parse each trade object: {"id":N,"symbol":"X","direction":"buy","lot":0.10}
   int pos = StringFind(json, "\"trades\"");
   if (pos < 0) return;

   while (true)
   {
      int id_pos = StringFind(json, "\"id\":", pos);
      if (id_pos < 0) break;
      int id_start = id_pos + 5;
      int id_end   = id_start;
      while (id_end < StringLen(json) && StringGetCharacter(json, id_end) >= '0' && StringGetCharacter(json, id_end) <= '9')
         id_end++;
      long trade_id = StringToInteger(StringSubstr(json, id_start, id_end - id_start));

      int sym_pos   = StringFind(json, "\"symbol\":\"", id_end);
      if (sym_pos < 0) break;
      int sym_start = sym_pos + 10;
      int sym_end   = StringFind(json, "\"", sym_start);
      string t_sym  = StringSubstr(json, sym_start, sym_end - sym_start);

      int dir_pos   = StringFind(json, "\"direction\":\"", sym_end);
      if (dir_pos < 0) break;
      int dir_start = dir_pos + 13;
      int dir_end   = StringFind(json, "\"", dir_start);
      string t_dir  = StringSubstr(json, dir_start, dir_end - dir_start);

      int lot_pos   = StringFind(json, "\"lot\":", dir_end);
      if (lot_pos < 0) break;
      int lot_start = lot_pos + 6;
      int lot_end   = lot_start;
      while (lot_end < StringLen(json) &&
             (StringGetCharacter(json, lot_end) == '.' ||
              (StringGetCharacter(json, lot_end) >= '0' && StringGetCharacter(json, lot_end) <= '9')))
         lot_end++;
      double t_lot = StringToDouble(StringSubstr(json, lot_start, lot_end - lot_start));

      int t_num_trades = 1;
      int nt_end       = lot_end;
      int nt_pos2      = StringFind(json, "\"num_trades\":", lot_end);
      if (nt_pos2 >= 0)
      {
         int nt_s = nt_pos2 + 13, nt_e = nt_s;
         while (nt_e < StringLen(json) && StringGetCharacter(json, nt_e) >= '0' && StringGetCharacter(json, nt_e) <= '9')
            nt_e++;
         t_num_trades = (int)StringToInteger(StringSubstr(json, nt_s, nt_e - nt_s));
         if (t_num_trades < 1) t_num_trades = 1;
         nt_end = nt_e;
      }

      // Validate parsed fields before executing
      if (trade_id <= 0 || t_sym == "" ||
          (t_dir != "buy" && t_dir != "sell") || t_lot <= 0.0)
      { pos = nt_end; continue; }

      ExecuteTrade(trade_id, t_sym, t_dir, t_lot, t_num_trades);
      pos = nt_end;
   }
}

//+------------------------------------------------------------------+
void ConfirmTrade(const long trade_id, const string status, const string err = "")
{
   char   data[];
   char   result[];
   string result_headers;

   string body = "api_key=" + InpApiKey +
                 "&action=confirm&trade_id=" + IntegerToString(trade_id) +
                 "&status=" + status +
                 (err != "" ? "&error_msg=" + err : "");
   string headers = "Content-Type: application/x-www-form-urlencoded\r\n";
   StringToCharArray(body, data, 0, StringLen(body));
   WebRequest("POST", InpBaseURL + "/api/trade.php", headers, 3000, data, result, result_headers);
}

//+------------------------------------------------------------------+
void ExecuteTrade(const long trade_id, const string app_sym, const string direction, const double lot, const int num_trades = 1)
{
   // ── 1. Terminal & EA trade permission checks ────────────────────
   if (!TerminalInfoInteger(TERMINAL_TRADE_ALLOWED))
   { ConfirmTrade(trade_id, "rejected", "AutoTrading disabled in terminal"); return; }

   if (!MQLInfoInteger(MQL_TRADE_ALLOWED))
   { ConfirmTrade(trade_id, "rejected", "EA trading not allowed in settings"); return; }

   if (AccountInfoInteger(ACCOUNT_TRADE_EXPERT) == 0)
   { ConfirmTrade(trade_id, "rejected", "Expert trading disabled on account"); return; }

   // ── 2. Map app symbol -> broker symbol ─────────────────────────
   string broker_sym = "";
   string up_app     = app_sym;
   StringToUpper(up_app);

   // Prefer the current chart symbol if it maps to this instrument
   int chart_idx = GetInstrumentIdx(Symbol());
   if (chart_idx >= 0 && g_instruments[chart_idx].app_symbol == up_app)
   {
      broker_sym = Symbol();
   }
   else
   {
      // Find any Market Watch symbol that maps to this app symbol
      for (int i = 0; i < g_broker_symbol_count; i++)
      {
         if (g_broker_map_idx[i] >= 0 &&
             g_instruments[g_broker_map_idx[i]].app_symbol == up_app)
         {
            broker_sym = g_broker_symbols[i];
            break;
         }
      }
   }

   if (broker_sym == "")
   { ConfirmTrade(trade_id, "rejected", "No broker symbol found for " + app_sym); return; }

   // ── 3. Symbol trading allowed ───────────────────────────────────
   if (!SymbolInfoInteger(broker_sym, SYMBOL_TRADE_MODE))
   { ConfirmTrade(trade_id, "rejected", broker_sym + " trading is disabled"); return; }

   // ── 4. Build request ────────────────────────────────────────────
   // Clamp and normalise lot to symbol constraints
   double min_lot  = SymbolInfoDouble(broker_sym, SYMBOL_VOLUME_MIN);
   double max_lot  = SymbolInfoDouble(broker_sym, SYMBOL_VOLUME_MAX);
   double lot_step = SymbolInfoDouble(broker_sym, SYMBOL_VOLUME_STEP);
   double norm_lot = MathMax(min_lot, MathMin(max_lot, lot));
   if (lot_step > 0.0) norm_lot = MathRound(norm_lot / lot_step) * lot_step;

   MqlTradeRequest request = {};
   MqlTradeResult  trade_result  = {};

   request.action       = TRADE_ACTION_DEAL;
   request.symbol       = broker_sym;
   request.volume       = norm_lot;
   request.type         = (direction == "buy") ? ORDER_TYPE_BUY : ORDER_TYPE_SELL;
   request.type_filling = GetFillingMode(broker_sym);
   request.price        = (direction == "buy")
                          ? SymbolInfoDouble(broker_sym, SYMBOL_ASK)
                          : SymbolInfoDouble(broker_sym, SYMBOL_BID);
   request.deviation    = 10;
   request.magic        = 20260306;
   request.comment      = "TradingDesk #" + IntegerToString(trade_id);

   // ── 5. OrderCheck – margin & fund validation ────────────────────
   MqlTradeCheckResult check = {};
   if (!OrderCheck(request, check))
   {
      string err = "OrderCheck failed: " + check.comment +
                   " (margin=" + DoubleToString(check.margin, 2) +
                   " free=" + DoubleToString(check.margin_free, 2) + ")";
      Print(err);
      ConfirmTrade(trade_id, "rejected", err);
      return;
   }

   // ── 6. Send order ───────────────────────────────────────────────
   if (!OrderSend(request, trade_result))
   {
      string err = "OrderSend failed: " + trade_result.comment +
                   " code=" + IntegerToString(GetLastError());
      Print(err);
      ConfirmTrade(trade_id, "failed", err);
      return;
   }

   Print("Trade executed: ", direction, " ", lot, " ", broker_sym,
         " ticket=", trade_result.order, " deal=", trade_result.deal);
   ConfirmTrade(trade_id, "executed");

   // Start / update good price series for this instrument
   AddOrUpdateSeries(broker_sym, direction, trade_result.price);
   Print("Good price started: ", direction, " ", broker_sym,
         " @ ", DoubleToString(trade_result.price, (int)SymbolInfoInteger(broker_sym, SYMBOL_DIGITS)));

   // Schedule remaining delayed trades (60s apart)
   if (num_trades > 1 && g_delayed_count < MAX_DELAYED)
   {
      g_delayed[g_delayed_count].broker_sym = broker_sym;
      g_delayed[g_delayed_count].direction  = direction;
      g_delayed[g_delayed_count].lot        = norm_lot;
      g_delayed[g_delayed_count].remaining  = num_trades - 1;
      g_delayed[g_delayed_count].next_fire  = TimeCurrent() + MathRand() % 5 + 1;
      g_delayed_count++;
      Print("Scheduled: ", num_trades - 1, " more trade(s) for ", broker_sym, " (1-5s random apart)");
   }
}

//+------------------------------------------------------------------+
// Count all open positions with our magic number
int CountMagicPositions()
{
   int count = 0;
   for (int i = 0; i < PositionsTotal(); i++)
   {
      if (PositionGetTicket(i) > 0 &&
          PositionGetInteger(POSITION_MAGIC) == 20260306)
         count++;
   }
   return count;
}

//+------------------------------------------------------------------+
// Count positions for a specific symbol+type with our magic number
int CountSeriesPositions(const string sym, ENUM_POSITION_TYPE pos_type)
{
   int count = 0;
   for (int i = 0; i < PositionsTotal(); i++)
   {
      if (PositionGetTicket(i) > 0 &&
          PositionGetString(POSITION_SYMBOL)  == sym &&
          PositionGetInteger(POSITION_TYPE)   == pos_type &&
          PositionGetInteger(POSITION_MAGIC)  == 20260306)
         count++;
   }
   return count;
}

//+------------------------------------------------------------------+
// Register or refresh a good price series after a successful order
void AddOrUpdateSeries(const string broker_sym, const string direction, double entry_price)
{
   for (int i = 0; i < g_series_count; i++)
   {
      if (g_series[i].broker_sym == broker_sym && g_series[i].direction == direction)
      {
         g_series[i].last_entry_price = entry_price;
         g_series[i].active           = true;
         // trade_count stays as-is so the expansion level is preserved
         return;
      }
   }
   if (g_series_count < MAX_SERIES)
   {
      g_series[g_series_count].broker_sym       = broker_sym;
      g_series[g_series_count].direction        = direction;
      g_series[g_series_count].last_entry_price = entry_price;
      g_series[g_series_count].trade_count      = 1;
      g_series[g_series_count].active           = true;
      g_series_count++;
   }
}

//+------------------------------------------------------------------+
// Fire a single good price entry for one series; updates the series state
void ExecuteGoodPriceTrade(int idx, int &total_pos)
{
   if (!TerminalInfoInteger(TERMINAL_TRADE_ALLOWED))   return;
   if (!MQLInfoInteger(MQL_TRADE_ALLOWED))             return;
   if (AccountInfoInteger(ACCOUNT_TRADE_EXPERT) == 0)  return;
   if (!SymbolInfoInteger(g_series[idx].broker_sym, SYMBOL_TRADE_MODE)) return;

   MqlTradeRequest request = {};
   MqlTradeResult  res     = {};

   request.action       = TRADE_ACTION_DEAL;
   request.symbol       = g_series[idx].broker_sym;
   request.volume       = 0.01;
   request.type         = (g_series[idx].direction == "buy") ? ORDER_TYPE_BUY : ORDER_TYPE_SELL;
   request.type_filling = GetFillingMode(g_series[idx].broker_sym);
   request.price        = (g_series[idx].direction == "buy")
                          ? SymbolInfoDouble(g_series[idx].broker_sym, SYMBOL_ASK)
                          : SymbolInfoDouble(g_series[idx].broker_sym, SYMBOL_BID);
   request.deviation    = 10;
   request.magic        = 20260306;
   request.comment      = "TradingDesk GP L" + IntegerToString(g_series[idx].trade_count);

   MqlTradeCheckResult check = {};
   if (!OrderCheck(request, check)) return;
   if (!OrderSend(request, res))    return;

   int level = g_series[idx].trade_count;
   g_series[idx].last_entry_price = res.price;
   g_series[idx].trade_count++;
   total_pos++;

   Print("Good price entry: ", g_series[idx].direction, " 0.01 ", g_series[idx].broker_sym,
         " @ ", DoubleToString(res.price, (int)SymbolInfoInteger(g_series[idx].broker_sym, SYMBOL_DIGITS)),
         " (level ", level, ")");
}

//+------------------------------------------------------------------+
// Check all active series and fire good price entries when triggered
void CheckGoodPriceSeries()
{
   int total_pos = CountMagicPositions();

   for (int i = 0; i < g_series_count; i++)
   {
      if (!g_series[i].active) continue;

      // Deactivate series if all positions for it are gone (user closed them)
      ENUM_POSITION_TYPE pos_type = (g_series[i].direction == "buy")
                                    ? POSITION_TYPE_BUY : POSITION_TYPE_SELL;
      if (CountSeriesPositions(g_series[i].broker_sym, pos_type) == 0)
      {
         g_series[i].active = false;
         continue;
      }

      // Respect global max trades cap
      if (total_pos >= g_max_trades) continue;

      int pp = GetPricePoints(g_series[i].broker_sym);
      if (pp < 0) continue;

      double point_size         = SymbolInfoDouble(g_series[i].broker_sym, SYMBOL_POINT);
      double expansion_factor   = 1.0 + g_good_price_expansion / 100.0;
      // gap[n] = pp * point_size * expansion_factor^(trade_count-1)
      double required_distance  = pp * point_size *
                                  MathPow(expansion_factor, g_series[i].trade_count - 1);

      bool trigger = false;
      if (g_series[i].direction == "buy")
      {
         double bid = SymbolInfoDouble(g_series[i].broker_sym, SYMBOL_BID);
         trigger = (bid <= g_series[i].last_entry_price - required_distance);
      }
      else
      {
         double ask = SymbolInfoDouble(g_series[i].broker_sym, SYMBOL_ASK);
         trigger = (ask >= g_series[i].last_entry_price + required_distance);
      }

      if (trigger)
         ExecuteGoodPriceTrade(i, total_pos);
   }
}

//+------------------------------------------------------------------+
// Fire delayed trades queued by num_trades > 1 (60s between each)
void ProcessDelayedTrades()
{
   if (!TerminalInfoInteger(TERMINAL_TRADE_ALLOWED)) return;
   if (!MQLInfoInteger(MQL_TRADE_ALLOWED))           return;

   for (int i = g_delayed_count - 1; i >= 0; i--)
   {
      if (TimeCurrent() < g_delayed[i].next_fire) continue;

      string sym = g_delayed[i].broker_sym;
      if (!SymbolInfoInteger(sym, SYMBOL_TRADE_MODE)) continue;

      // Clamp and normalise stored lot to current symbol constraints
      double d_min  = SymbolInfoDouble(sym, SYMBOL_VOLUME_MIN);
      double d_max  = SymbolInfoDouble(sym, SYMBOL_VOLUME_MAX);
      double d_step = SymbolInfoDouble(sym, SYMBOL_VOLUME_STEP);
      double d_lot  = MathMax(d_min, MathMin(d_max, g_delayed[i].lot));
      if (d_step > 0.0) d_lot = MathRound(d_lot / d_step) * d_step;

      MqlTradeRequest req = {};
      MqlTradeResult  res = {};
      req.action       = TRADE_ACTION_DEAL;
      req.symbol       = sym;
      req.volume       = d_lot;
      req.type         = (g_delayed[i].direction == "buy") ? ORDER_TYPE_BUY : ORDER_TYPE_SELL;
      req.type_filling = GetFillingMode(sym);
      req.price        = (g_delayed[i].direction == "buy")
                         ? SymbolInfoDouble(sym, SYMBOL_ASK)
                         : SymbolInfoDouble(sym, SYMBOL_BID);
      req.deviation    = 10;
      req.magic        = 20260306;
      req.comment      = "TradingDesk D" + IntegerToString(g_delayed[i].remaining);

      MqlTradeCheckResult check = {};
      if (!OrderCheck(req, check)) continue;
      if (!OrderSend(req, res))    continue;

      Print("Delayed trade: ", g_delayed[i].direction, " ", g_delayed[i].lot, " ", sym,
            " ticket=", res.order, " (", g_delayed[i].remaining, " remaining)");
      AddOrUpdateSeries(sym, g_delayed[i].direction, res.price);

      g_delayed[i].remaining--;
      if (g_delayed[i].remaining <= 0)
      {
         // Compact the array
         for (int j = i; j < g_delayed_count - 1; j++)
            g_delayed[j] = g_delayed[j + 1];
         g_delayed_count--;
      }
      else
         g_delayed[i].next_fire = TimeCurrent() + MathRand() % 5 + 1;
   }
}

//+------------------------------------------------------------------+
// Confirm a manage command result back to the server
void ConfirmManage(const long cmd_id, const string status, const string msg = "")
{
   char   data[];
   char   result[];
   string result_headers;
   string body = "api_key=" + InpApiKey +
                 "&action=confirm_manage&cmd_id=" + IntegerToString(cmd_id) +
                 "&status=" + status +
                 (msg != "" ? "&result_msg=" + msg : "");
   string headers = "Content-Type: application/x-www-form-urlencoded\r\n";
   StringToCharArray(body, data, 0, StringLen(body));
   WebRequest("POST", InpBaseURL + "/api/trade.php", headers, 3000, data, result, result_headers);
}

//+------------------------------------------------------------------+
// Close a single position by ticket
bool ClosePosition(ulong ticket)
{
   if (!PositionSelectByTicket(ticket)) return false;
   string sym = PositionGetString(POSITION_SYMBOL);
   MqlTradeRequest req = {};
   MqlTradeResult  res = {};
   req.action       = TRADE_ACTION_DEAL;
   req.symbol       = sym;
   req.volume       = PositionGetDouble(POSITION_VOLUME);
   req.type         = (PositionGetInteger(POSITION_TYPE) == POSITION_TYPE_BUY)
                      ? ORDER_TYPE_SELL : ORDER_TYPE_BUY;
   req.type_filling = GetFillingMode(sym);
   req.price        = (req.type == ORDER_TYPE_SELL)
                      ? SymbolInfoDouble(sym, SYMBOL_BID)
                      : SymbolInfoDouble(sym, SYMBOL_ASK);
   req.deviation    = 20;
   req.magic        = 20260306;
   req.position     = ticket;
   return OrderSend(req, res);
}

//+------------------------------------------------------------------+
void ExecBreakEven(long cmd_id)
{
   int moved = 0;
   for (int i = PositionsTotal() - 1; i >= 0; i--)
   {
      ulong ticket = PositionGetTicket(i);
      if (!PositionSelectByTicket(ticket)) continue;
      if (PositionGetInteger(POSITION_MAGIC) != 20260306) continue;

      double open_price = PositionGetDouble(POSITION_PRICE_OPEN);
      double cur_price  = PositionGetDouble(POSITION_PRICE_CURRENT);
      double cur_sl     = PositionGetDouble(POSITION_SL);
      string sym        = PositionGetString(POSITION_SYMBOL);
      long   ptype      = PositionGetInteger(POSITION_TYPE);

      // Only move SL to break even if position is in profit
      bool in_profit = (ptype == POSITION_TYPE_BUY)
                       ? (cur_price > open_price)
                       : (cur_price < open_price);
      if (!in_profit) continue;

      // Skip if SL is already at or beyond break even
      bool already_be = (ptype == POSITION_TYPE_BUY)
                        ? (cur_sl >= open_price)
                        : (cur_sl <= open_price && cur_sl > 0);
      if (already_be) continue;

      MqlTradeRequest req = {};
      MqlTradeResult  res = {};
      req.action   = TRADE_ACTION_SLTP;
      req.symbol   = sym;
      req.sl       = open_price;
      req.tp       = PositionGetDouble(POSITION_TP);
      req.position = ticket;
      if (OrderSend(req, res)) moved++;
   }
   Print("Break even: moved ", moved, " positions");
   ConfirmManage(cmd_id, "done", "Moved " + IntegerToString(moved) + " positions to break even");
}

//+------------------------------------------------------------------+
void ExecDeleteSL(long cmd_id)
{
   int removed = 0;
   for (int i = PositionsTotal() - 1; i >= 0; i--)
   {
      ulong ticket = PositionGetTicket(i);
      if (!PositionSelectByTicket(ticket)) continue;
      if (PositionGetInteger(POSITION_MAGIC) != 20260306) continue;
      if (PositionGetDouble(POSITION_SL) == 0) continue;

      MqlTradeRequest req = {};
      MqlTradeResult  res = {};
      req.action   = TRADE_ACTION_SLTP;
      req.symbol   = PositionGetString(POSITION_SYMBOL);
      req.sl       = 0;
      req.tp       = PositionGetDouble(POSITION_TP);
      req.position = ticket;
      if (OrderSend(req, res)) removed++;
   }
   Print("Delete SL: removed from ", removed, " positions");
   ConfirmManage(cmd_id, "done", "Removed SL from " + IntegerToString(removed) + " positions");
}

//+------------------------------------------------------------------+
void ExecCloseLosing(long cmd_id)
{
   int closed = 0;
   for (int i = PositionsTotal() - 1; i >= 0; i--)
   {
      ulong ticket = PositionGetTicket(i);
      if (!PositionSelectByTicket(ticket)) continue;
      if (PositionGetInteger(POSITION_MAGIC) != 20260306) continue;
      if (PositionGetDouble(POSITION_PROFIT) >= 0) continue;
      if (ClosePosition(ticket)) closed++;
   }
   Print("Close losing: closed ", closed, " positions");
   ConfirmManage(cmd_id, "done", "Closed " + IntegerToString(closed) + " losing positions");
}

//+------------------------------------------------------------------+
void ExecCloseProfitable(long cmd_id)
{
   int closed = 0;
   for (int i = PositionsTotal() - 1; i >= 0; i--)
   {
      ulong ticket = PositionGetTicket(i);
      if (!PositionSelectByTicket(ticket)) continue;
      if (PositionGetInteger(POSITION_MAGIC) != 20260306) continue;
      if (PositionGetDouble(POSITION_PROFIT) <= 0) continue;
      if (ClosePosition(ticket)) closed++;
   }
   Print("Close profitable: closed ", closed, " positions");
   ConfirmManage(cmd_id, "done", "Closed " + IntegerToString(closed) + " profitable positions");
}

//+------------------------------------------------------------------+
void ExecCloseAll(long cmd_id)
{
   int closed = 0;
   for (int i = PositionsTotal() - 1; i >= 0; i--)
   {
      ulong ticket = PositionGetTicket(i);
      if (!PositionSelectByTicket(ticket)) continue;
      if (PositionGetInteger(POSITION_MAGIC) != 20260306) continue;
      if (ClosePosition(ticket)) closed++;
   }
   Print("Close all: closed ", closed, " positions");
   ConfirmManage(cmd_id, "done", "Closed " + IntegerToString(closed) + " positions");
}

//+------------------------------------------------------------------+
void PollManageCommands()
{
   if (!TerminalInfoInteger(TERMINAL_TRADE_ALLOWED)) return;
   if (!MQLInfoInteger(MQL_TRADE_ALLOWED))           return;

   char   data[];
   char   result[];
   string result_headers;

   string body    = "api_key=" + InpApiKey + "&action=poll_manage";
   string headers = "Content-Type: application/x-www-form-urlencoded\r\n";
   StringToCharArray(body, data, 0, StringLen(body));

   int http_code = WebRequest("POST", InpBaseURL + "/api/trade.php",
                              headers, 3000, data, result, result_headers);
   if (http_code != 200) return;

   string json = CharArrayToString(result);
   if (StringFind(json, "\"ok\":true") < 0) return;

   int pos = StringFind(json, "\"commands\"");
   if (pos < 0) return;

   while (true)
   {
      int id_pos = StringFind(json, "\"id\":", pos);
      if (id_pos < 0) break;
      int id_s = id_pos + 5, id_e = id_s;
      while (id_e < StringLen(json) && StringGetCharacter(json, id_e) >= '0' && StringGetCharacter(json, id_e) <= '9') id_e++;
      long cmd_id = StringToInteger(StringSubstr(json, id_s, id_e - id_s));
      if (cmd_id <= 0) { pos = id_e; continue; }

      int cmd_pos = StringFind(json, "\"command\":\"", id_e);
      if (cmd_pos < 0) break;
      int cmd_s = cmd_pos + 11;
      int cmd_e = StringFind(json, "\"", cmd_s);
      string command = StringSubstr(json, cmd_s, cmd_e - cmd_s);
      if (command == "") { pos = cmd_e; continue; }

      if      (command == "break_even")      ExecBreakEven(cmd_id);
      else if (command == "delete_sl")       ExecDeleteSL(cmd_id);
      else if (command == "close_losing")    ExecCloseLosing(cmd_id);
      else if (command == "close_profitable")ExecCloseProfitable(cmd_id);
      else if (command == "close_all")       ExecCloseAll(cmd_id);
      else                                   ConfirmManage(cmd_id, "failed", "Unknown command: " + command);

      pos = cmd_e;
   }
}

//+------------------------------------------------------------------+
void PushStats()
{
   if (!TerminalInfoInteger(TERMINAL_CONNECTED)) return;

   char   data[];
   char   result[];
   string result_headers;

   double profit  = AccountInfoDouble(ACCOUNT_PROFIT);
   double equity  = AccountInfoDouble(ACCOUNT_EQUITY);
   double balance = AccountInfoDouble(ACCOUNT_BALANCE);

   string body = "api_key=" + InpApiKey
               + "&action=push"
               + "&profit="  + DoubleToString(profit,  2)
               + "&equity="  + DoubleToString(equity,  2)
               + "&balance=" + DoubleToString(balance, 2);

   string headers = "Content-Type: application/x-www-form-urlencoded\r\n";
   StringToCharArray(body, data, 0, StringLen(body));
   WebRequest("POST", InpBaseURL + "/api/stats.php", headers, 2000, data, result, result_headers);
}

//+------------------------------------------------------------------+
// Detect the best supported filling mode for a symbol
ENUM_ORDER_TYPE_FILLING GetFillingMode(const string sym)
{
   int flags = (int)SymbolInfoInteger(sym, SYMBOL_FILLING_MODE);
   if ((flags & SYMBOL_FILLING_FOK) != 0) return ORDER_FILLING_FOK;
   if ((flags & SYMBOL_FILLING_IOC) != 0) return ORDER_FILLING_IOC;
   return ORDER_FILLING_RETURN;
}

//+------------------------------------------------------------------+
// Map broker symbol to an index in g_instruments[]
// Priority: 1) exact (case-insensitive)  2) broker contains app symbol
//           3) app symbol contains broker  4) alias table
int MapSymbol(const string broker_sym)
{
   string b = broker_sym;
   StringToUpper(b);

   for (int i = 0; i < g_instrument_count; i++)
   {
      string a = g_instruments[i].app_symbol;
      StringToUpper(a);

      // 1. Exact
      if (b == a) return i;

      // 2. Broker symbol contains app symbol (r.XAUUSD, XAUUSDm, XAUUSD.ecn …)
      if (StringFind(b, a) >= 0) return i;

      // 3. App symbol contains broker (unlikely but covers short broker names)
      if (StringFind(a, b) >= 0) return i;
   }

   // 4. Alias lookup (GOLD -> XAUUSD, WTI -> USOIL, etc.)
   for (int i = 0; i < ArraySize(g_alias_from); i++)
   {
      if (b == g_alias_from[i])
         for (int j = 0; j < g_instrument_count; j++)
            if (g_alias_to[i] == g_instruments[j].app_symbol) return j;
   }

   return -1;
}
//+------------------------------------------------------------------+
