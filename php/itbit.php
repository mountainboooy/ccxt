<?php

namespace ccxt;

include_once ('base/Exchange.php');

class itbit extends Exchange {

    public function describe () {
        return array_replace_recursive (parent::describe (), array (
            'id' => 'itbit',
            'name' => 'itBit',
            'countries' => 'US',
            'rateLimit' => 2000,
            'version' => 'v1',
            'hasCORS' => true,
            'urls' => array (
                'logo' => 'https://user-images.githubusercontent.com/1294454/27822159-66153620-60ad-11e7-89e7-005f6d7f3de0.jpg',
                'api' => 'https://api.itbit.com',
                'www' => 'https://www.itbit.com',
                'doc' => array (
                    'https://api.itbit.com/docs',
                    'https://www.itbit.com/api',
                ),
            ),
            'api' => array (
                'public' => array (
                    'get' => array (
                        'markets/{symbol}/ticker',
                        'markets/{symbol}/order_book',
                        'markets/{symbol}/trades',
                    ),
                ),
                'private' => array (
                    'get' => array (
                        'wallets',
                        'wallets/{walletId}',
                        'wallets/{walletId}/balances/{currencyCode}',
                        'wallets/{walletId}/funding_history',
                        'wallets/{walletId}/trades',
                        'wallets/{walletId}/orders/{id}',
                    ),
                    'post' => array (
                        'wallet_transfers',
                        'wallets',
                        'wallets/{walletId}/cryptocurrency_deposits',
                        'wallets/{walletId}/cryptocurrency_withdrawals',
                        'wallets/{walletId}/orders',
                        'wire_withdrawal',
                    ),
                    'delete' => array (
                        'wallets/{walletId}/orders/{id}',
                    ),
                ),
            ),
            'markets' => array (
                'BTC/USD' => array ( 'id' => 'XBTUSD', 'symbol' => 'BTC/USD', 'base' => 'BTC', 'quote' => 'USD' ),
                'BTC/SGD' => array ( 'id' => 'XBTSGD', 'symbol' => 'BTC/SGD', 'base' => 'BTC', 'quote' => 'SGD' ),
                'BTC/EUR' => array ( 'id' => 'XBTEUR', 'symbol' => 'BTC/EUR', 'base' => 'BTC', 'quote' => 'EUR' ),
            ),
            'fees' => array (
                'trading' => array (
                    'maker' => 0,
                    'taker' => 0.2 / 100,
                ),
            ),
        ));
    }

    public function fetch_order_book ($symbol, $params = array ()) {
        $orderbook = $this->publicGetMarketsSymbolOrderBook (array_merge (array (
            'symbol' => $this->market_id($symbol),
        ), $params));
        return $this->parse_order_book($orderbook);
    }

    public function fetch_ticker ($symbol, $params = array ()) {
        $ticker = $this->publicGetMarketsSymbolTicker (array_merge (array (
            'symbol' => $this->market_id($symbol),
        ), $params));
        $serverTimeUTC = (array_key_exists ('serverTimeUTC', $ticker));
        if (!$serverTimeUTC)
            throw new ExchangeError ($this->id . ' fetchTicker returned a bad response => ' . $this->json ($ticker));
        $timestamp = $this->parse8601 ($ticker['serverTimeUTC']);
        $vwap = floatval ($ticker['vwap24h']);
        $baseVolume = floatval ($ticker['volume24h']);
        $quoteVolume = $baseVolume * $vwap;
        return array (
            'symbol' => $symbol,
            'timestamp' => $timestamp,
            'datetime' => $this->iso8601 ($timestamp),
            'high' => floatval ($ticker['high24h']),
            'low' => floatval ($ticker['low24h']),
            'bid' => $this->safe_float($ticker, 'bid'),
            'ask' => $this->safe_float($ticker, 'ask'),
            'vwap' => $vwap,
            'open' => floatval ($ticker['openToday']),
            'close' => null,
            'first' => null,
            'last' => floatval ($ticker['lastPrice']),
            'change' => null,
            'percentage' => null,
            'average' => null,
            'baseVolume' => $baseVolume,
            'quoteVolume' => $quoteVolume,
            'info' => $ticker,
        );
    }

    public function parse_trade ($trade, $market) {
        $timestamp = $this->parse8601 ($trade['timestamp']);
        $id = (string) $trade['matchNumber'];
        return array (
            'info' => $trade,
            'timestamp' => $timestamp,
            'datetime' => $this->iso8601 ($timestamp),
            'symbol' => $market['symbol'],
            'id' => $id,
            'order' => $id,
            'type' => null,
            'side' => null,
            'price' => floatval ($trade['price']),
            'amount' => floatval ($trade['amount']),
        );
    }

    public function fetch_trades ($symbol, $since = null, $limit = null, $params = array ()) {
        $market = $this->market ($symbol);
        $response = $this->publicGetMarketsSymbolTrades (array_merge (array (
            'symbol' => $market['id'],
        ), $params));
        return $this->parse_trades($response['recentTrades'], $market);
    }

    public function fetch_balance ($params = array ()) {
        $response = $this->privateGetBalances ();
        $balances = $response['balances'];
        $result = array ( 'info' => $response );
        for ($b = 0; $b < count ($balances); $b++) {
            $balance = $balances[$b];
            $currency = $balance['currency'];
            $account = array (
                'free' => floatval ($balance['availableBalance']),
                'used' => 0.0,
                'total' => floatval ($balance['totalBalance']),
            );
            $account['used'] = $account['total'] - $account['free'];
            $result[$currency] = $account;
        }
        return $this->parse_balance($result);
    }

    public function fetch_wallets () {
        return $this->privateGetWallets ();
    }

    public function nonce () {
        return $this->milliseconds ();
    }

    public function create_order ($symbol, $type, $side, $amount, $price = null, $params = array ()) {
        if ($type == 'market')
            throw new ExchangeError ($this->id . ' allows limit orders only');
        $walletIdInParams = (array_key_exists ('walletId', $params));
        if (!$walletIdInParams)
            throw new ExchangeError ($this->id . ' createOrder requires a walletId parameter');
        $amount = (string) $amount;
        $price = (string) $price;
        $market = $this->market ($symbol);
        $order = array (
            'side' => $side,
            'type' => $type,
            'currency' => $market['base'],
            'amount' => $amount,
            'display' => $amount,
            'price' => $price,
            'instrument' => $market['id'],
        );
        $response = $this->privatePostTradeAdd (array_merge ($order, $params));
        return array (
            'info' => $response,
            'id' => $response['id'],
        );
    }

    public function cancel_order ($id, $symbol = null, $params = array ()) {
        $walletIdInParams = (array_key_exists ('walletId', $params));
        if (!$walletIdInParams)
            throw new ExchangeError ($this->id . ' cancelOrder requires a walletId parameter');
        return $this->privateDeleteWalletsWalletIdOrdersId (array_merge (array (
            'id' => $id,
        ), $params));
    }

    public function sign ($path, $api = 'public', $method = 'GET', $params = array (), $headers = null, $body = null) {
        $url = $this->urls['api'] . '/' . $this->version . '/' . $this->implode_params($path, $params);
        $query = $this->omit ($params, $this->extract_params($path));
        if ($api == 'public') {
            if ($query)
                $url .= '?' . $this->urlencode ($query);
        } else {
            if ($query)
                $body = $this->json ($query);
            else
                $body = '';
            $nonce = (string) $this->nonce ();
            $timestamp = $nonce;
            $auth = array ( $method, $url, $body, $nonce, $timestamp );
            $message = $nonce . $this->json ($auth);
            $hash = $this->hash ($this->encode ($message), 'sha256', 'binary');
            $binhash = $this->binary_concat($url, $hash);
            $signature = $this->hmac ($binhash, $this->encode ($this->secret), 'sha512', 'base64');
            $headers = array (
                'Authorization' => self.apiKey . ':' . $signature,
                'Content-Type' => 'application/json',
                'X-Auth-Timestamp' => $timestamp,
                'X-Auth-Nonce' => $nonce,
            );
        }
        return array ( 'url' => $url, 'method' => $method, 'body' => $body, 'headers' => $headers );
    }

    public function request ($path, $api = 'public', $method = 'GET', $params = array (), $headers = null, $body = null) {
        $response = $this->fetch2 ($path, $api, $method, $params, $headers, $body);
        if (array_key_exists ('code', $response))
            throw new ExchangeError ($this->id . ' ' . $this->json ($response));
        return $response;
    }
}

?>