<?php
define('H_TIME', 300);

$handlers = [
	'map' => function($user)
	{
		global $redis;

                $allKeys = $redis->keys('miniseries.S00.*');

		$totalMap = [];

		foreach ($allKeys as $key => $val) {
        		$u = $redis->get($val);

			$u = json_decode($u, true);

			if (null == $u['myInventory']) {
				continue;
			}

		foreach ($u['myInventory'] as $k => $v) {
			if (null != $v['placeId']) {
				$v['ownerId'] = hash('sha1', str_replace('miniseries.S00.', '', $val));

				$totalMap[$v['placeId']][] = $v;
			}
		}
		}

		foreach ($totalMap as $k => $v) {
			$item = [];

			$item['placeId'] = $k;

			$item['inventoryPalaceUID'] = null;
			$item['palaceResourceKey'] = null;

			foreach ($v as $val) {
				if (1 == $val['filterType']) {
					$item['size'] = $val['size'];
					$item['ownerId'] = $val['ownerId'];
					$item['inventoryPlatformUID'] = $val['uid'];
					$item['platformResourceKey'] = $val['id'];
				}

				if (2 == $val['filterType']) {
					$item['inventoryPalaceUID'] = $val['uid'];
					$item['palaceResourceKey'] = $val['id'];
				}
			}

			$allMap[] = $item;
		}

		okReply($allMap);
	},

	'map-put' => function($user)
	{
		$post = file_get_contents('php://input');

		$r = json_decode(trim(urldecode($post)), true);

		$uid = $r['uid'];
		$place = $r['placeId'];

		foreach ($user['myInventory'] as $k => $v) {
			if ($v['uid'] == $uid) {
				if (null != @$v['placeId']) {
					dieStop(409);
				}

				$v['placeId'] = $place;
				$user['myInventory'][$k] = $v;
			}
		}

		global $handlers;

                $handlers['user-info']($user);
	},

	'map-take' => function($user)
	{
		$post = file_get_contents('php://input');

		$r = json_decode(trim(urldecode($post)), true);

		$place = $r['placeId'];
		$filter = $r['filterType'];

		if (1 == $filter) {
			foreach ($user['myInventory'] as $k => $v) {
				if ($v['placeId'] == $place) {
					unset($user['myInventory'][$k]['placeId']);
				}
			}
		}

		if (2 == $filter) {
			foreach ($user['myInventory'] as $k => $v) {
				if ($v['placeId'] == $place && 2 == $v['filterType']) {
					unset($user['myInventory'][$k]['placeId']);
				}
			}
		}

		global $handlers;

                $handlers['user-info']($user);
	},

	'warehouse' => function($user)
	{
		$base = file_get_contents('base-prices.json');

		$base = json_decode($base, true);

		$palace = file_get_contents('palace-prices.json');

		$palace = json_decode($palace, true);

		okReply(
			array_merge(
				$base,
				$palace
			)
		);
	},

	'warehouse-buy' => function($user)
	{
		$base = file_get_contents('base-prices.json');

		$base = json_decode($base, true);

		$palace = file_get_contents('palace-prices.json');

		$palace = json_decode($palace, true);

		$items = array_merge(
			$base,
			$palace
		);

		$post = file_get_contents('php://input');

		$r = json_decode(trim(urldecode($post)), true);

		$buy = null;

		foreach ($items as $v) {
			if ($v['id'] == $r['id']) {
				$buy = $v;
			}
		}

		if (null == $buy) {
			dieStop(404);
		}

		if ($user['balance']['score'] - $buy['price'] < 0) {
			dieStop(403);
		}

		$user['balance']['score'] -= $buy['price'];

		$buy['uid'] = sha1(microtime());

		$user['myInventory'][] = $buy;

		//putUser($user);
		
		global $handlers;

		$handlers['user-info']($user);
	},

	'user-info' => function($user)
	{
		//print_r($user);exit;
		if (null != $user['lostHeart']) {
			$t = time();
			$dt = $t - $user['lostHeart'];

			if ($dt > H_TIME) {
				$hearts = floor($dt / H_TIME);

				$hd = $user['balance']['hearts']['capacity'] - $user['balance']['hearts']['charge'];

				if ($hearts > $hd) {
					$hearts = $hd;
				}

				$user['balance']['hearts']['charge'] += $hearts;


				if ($user['balance']['hearts']['charge'] == $user['balance']['hearts']['capacity']) {
					$user['balance']['hearts']['timeout'] = 0;

					unset($user['lostHeart']);
				} else {
					$dd = $dt - H_TIME * $hearts;

					if ($hd > 1) {
						$user['lostHeart'] = time() - $dd;
						$user['balance']['hearts']['timeout'] = $dd;
					}
				}
			} else {
				$timeout = H_TIME - $dt;

				if ($timeout > 0) {
					$user['balance']['hearts']['timeout'] = $timeout;
				} else {
					$hd = $user['balance']['hearts']['capacity'] - $user['balance']['hearts']['charge'];

					if ($hd > 0) {
						$user['balance']['hearts']['charge']++;

						if ($hd > 1) {
							$user['lostHeart'] = time();
							$user['balance']['hearts']['timeout'] = $timeout;
						}
					}

					if ($user['balance']['hearts']['charge'] == $user['balance']['hearts']['capacity']) {
						$user['balance']['hearts']['timeout'] = 0;

						unset($user['lostHeart']);
					}
				}
			}
		}

		putUser($user);

		$spot['balance'] = $user['balance'];
		$spot['nickname'] = $user['nickname'];
		$spot['myInventory'] = $user['myInventory'];

		$dTimer = (float)((time() % 3600) / 900);
		$dTimer -= floor($dTimer);


		$reply = array_merge(
			$spot,

			[
				'config' => [
                                	'dayTime' => (BOOL)(((floor((int)date('i') / 15) % 2))),
					'dayTimeValue' => (string)number_format($dTimer, 8, '.', ''),
                                	'maxSessionTime' => '600.0',
                                	'maxSteps' => 10,
					'tutorialURL' => 'https://play.memoverse.io/tutorial',
					'assetsURL' => 'https://cdn.memoverse.io/miniseries/S00/v0.5.53/StreamingAssets/'
                        	]
			]
		);



		okReply($reply);
	},

	'roadmap' => function($user)
	{
		$reply = [
			'steps' => $user['balance']['steps'],

		];

		if (0 != $user['balance']['steps']) {
			foreach ($user['games'] as $v) {
				if (null == $v['result']) {
					continue;
				}

				$reply['checkpoints'][] = [
					'time' => number_format((float)$v['result'], 3, '.', ''),
					'score' => ($v['score'] == null ? 0 : $v['score'])
				];
			}
		}

		okReply($reply);
	},

	'leaderboard' => function($user)
	{
		global $redis;

		$allKeys = $redis->keys('miniseries.S00.*');

foreach ($allKeys as $v) {
        $u = $redis->get($v);

        $u = json_decode($u, true);

        if ($u['balance']['steps'] >= 10) {
                $result['leaderboard'][] = [
                        'nickname' => $u['nickname'],
                        'time' => $u['balance']['time'],
                        'score' => $u['balance']['score']
                ];
        }
}

usort($result['leaderboard'],
        function($a, $b)
        {
                $l = floatval($a['time']);
                $r = floatval($b['time']);

                if ($l == $r) {
                        return 0;
                }

                return ($l < $r) ? -1 : 1;
        }
);

foreach ($result['leaderboard'] as $k => $v) {
	if (floatval($v['time']) == floatval($user['balance']['time'])) {
		$result['position'] = $k + 1;
	}
}

$result['score'] = $user['balance']['score'];
$result['time'] = $user['balance']['time'];

$result['leaderboard'] = array_slice($result['leaderboard'], 0, 10);

	okReply($result);
	},

	'ping' => function($user)
	{
		$i = [
			0,

			file_get_contents('php://input')
		];

		file_put_contents('./back.log', serialize($i) . PHP_EOL, FILE_APPEND);

		if (null != $user['lostHeart']) {
			$t = time();
			$dt = $t - $user['lostHeart'];

			if ($dt > H_TIME) {
				$hearts = floor($dt / H_TIME);

				$hd = $user['balance']['hearts']['capacity'] - $user['balance']['hearts']['charge'];

				if ($hearts > $hd) {
					$hearts = $hd;
				}

				$user['balance']['hearts']['charge'] += $hearts;


				if ($user['balance']['hearts']['charge'] == $user['balance']['hearts']['capacity']) {
					$user['balance']['hearts']['timeout'] = 0;

					unset($user['lostHeart']);
				} else {
					$dd = $dt - H_TIME * $hearts;

					if ($hd > 1) {
						$user['lostHeart'] = time() - $dd;
						$user['balance']['hearts']['timeout'] = $dd;
					}
				}
			} else {
				$timeout = H_TIME - $dt;

				if ($timeout > 0) {
					$user['balance']['hearts']['timeout'] = $timeout;
				} else {
					$hd = $user['balance']['hearts']['capacity'] - $user['balance']['hearts']['charge'];

					if ($hd > 0) {
						$user['balance']['hearts']['charge']++;

						if ($hd > 1) {
							$user['lostHeart'] = time();
							$user['balance']['hearts']['timeout'] = $timeout;
						}
					}

					if ($user['balance']['hearts']['charge'] == $user['balance']['hearts']['capacity']) {
						$user['balance']['hearts']['timeout'] = 0;

						unset($user['lostHeart']);
					}
				}
			}
		}

		putUser($user);

		$spot['balance'] = $user['balance'];

		$dTimer = (float)((time() % 3600) / 900);
                $dTimer -= floor($dTimer);

		$reply = array_merge(
			$spot,

			[
				'mapUpdateRequired' => (BOOL)(mt_rand() % 2),
				//'mapUpdateRequired' => false,
				'dayTime' => (BOOL)(((floor((int)date('i') / 15) % 2))),
				'dayTimeValue' => number_format($dTimer, 8, '.', '')
			]
		);


		okReply($reply);
	},

	'start' => function($user)
	{
		$post = file_get_contents('php://input');

		$i = [
			1,

			$post
		];

		file_put_contents('./back.log', serialize($i) . PHP_EOL, FILE_APPEND);

		$id = sha1(microtime(true) . microtime());

		$s = $j = json_decode(trim(urldecode($post)), true);

		$s = explode('.', $s['localtime']);

		file_put_contents('./back.log', serialize($j) . PHP_EOL, FILE_APPEND);

		$dt = time() . '.' . $s[1];

		$user['games'][$id]['start'] = $dt;

		putUser($user);

		okReply(
			[
				'gameID' => $id
			]
		);
	},

	'lost-heart' => function($user)
	{
		$i = [
			2,

			file_get_contents('php://input')
		];

		file_put_contents('./back.log', serialize($i) . PHP_EOL, FILE_APPEND);

		if (0 == $user['balance']['hearts']['capacity']) {
			dieStop(402);
		}

		$user['balance']['hearts']['charge']--;

		$user['lostHeart'] = time();

		putUser($user);

		dieStop(200);
	},

	'finish' => function($user)
	{
		$post = file_get_contents('php://input');

		$i = [
			3,

			$post
		];

		file_put_contents('./back.log', serialize($i) . PHP_EOL, FILE_APPEND);

		$s = json_decode(urldecode($post), true);

		if (null == $s['gameID']) {
			dieStop(400);
		}

		$id = $s['gameID'];

		if (null == $user['games'][$id]) {
			dieStop(403);
		}

		$start = $user['games'][$id]['start'];

		$finish = explode('.', $s['localtime']);

		$finish = time() . '.' . $finish[1];

		$dt = ((float)($finish) - (float)($start));


		if (true == $s['result']) {
			/*
			if ($dt > (float)600) {
				unset($user['games'][$id]);

				putUser($user);

				dieStop(403);
			}
			*/

			$user['games'][$id]['finish'] = $finish;

			$user['games'][$id]['result'] = $dt;

			if ($user['balance']['steps'] < 10) {
				$user['balance']['steps']++;

				$bal = ceil(($dt / 10));

				$bal = 60 - $bal;

				if ($bal < 0) {
					$bal = 0;
				}

				$user['balance']['score'] += $bal;

				$user['games'][$id]['score'] = $bal;
			} else {
				$bal = 0;
			}

			if ($dt < floatval($user['balance']['time']) || null == $user['balance']['time'])
				$user['balance']['time'] = number_format((float)$dt, 3, '.', '');

			putUser($user);

			$spot['balance'] = $user['balance'];

			okReply(
				array_merge(
					$spot,

					[
						'score' => $bal,

						'time' => number_format((float)$dt, 3, '.', '')
					]
				)
			);

		} else {
			unset($user['games'][$id]);

			putUser($user);

			$spot['balance'] = $user['balance'];

			okReply(
				array_merge(
					$spot,

					[
						'score' => 0,

						'time' => number_format((float)$dt, 3, '.', '')
					]
				)
			);
		}
	}
];

function dieStop($code = 401) 
{

	header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Headers: Content-Type, X-Auth-Token, Authorization, Origin');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS, PUT, DELETE');
	http_response_code($code);

	die();
}

function okReply($reply)
{
	http_response_code(200);

	$im = json_encode($reply);

	header('Access-Control-Allow-Origin: *');
	header('Access-Control-Allow-Credentials: true');
	header('Access-Control-Allow-Headers: Content-Type, X-Auth-Token, Authorization, Origin');
	header('Access-Control-Allow-Methods: POST, GET, OPTIONS, PUT, DELETE');

	header('Content-Type: application/json');
	header("Content-Length: " . strlen($im));

	print $im;

	exit;
}

$req = ltrim(rtrim(parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH), '/'), '/');

$req = explode('/', $req, 4);

$aid = $req[1];
$aep = $req[2];
$sid = $req[3];

$log['params'] = $req;
$log['post'] = file_get_contents('php://input');
$log['ip'] = $_SERVER['REMOTE_ADDR'];
$log['ua'] = $_SERVER['HTTP_USER_AGENT'];
$log['time'] = time();

file_put_contents('requests.log', serialize($log) . PHP_EOL, FILE_APPEND);



// check user's aid here (and on nginx level too)
//if ('5fd8e4620ebca6cb6289cd26716922f80456f8c2' != $aid && 'test-api-endpoint' != $aid) {
//	dieStop();
//}

// check session id here (and get user's profile)
//if ('d6823551f24b86fcf7b15e18d151eadbf437fa33' != $sid) {
//	dieStop(404);
//}



//if (false == array_key_exists($aep, $handlers)) {
	//dieStop(501);
//}


$redis = new Redis();

//$redis->connect('199.192.24.137', 6379);
$redis->connect('127.0.0.1', 6379);

$key = 'miniseries.S00.' . $sid;


$user = $redis->get($key);

if (null == $user) {
	dieStop(404);
}

function putUser($user)
{
	global $redis;
	global $key;

	$redis->set($key, json_encode($user));
}

$handlers[$aep](json_decode($user, true));
