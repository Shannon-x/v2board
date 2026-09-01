<?php

namespace App\Services;


use App\Models\Payment;

class PaymentService
{
    public $method;
    protected $class;
    protected $config;
    protected $payment;

    public function __construct($method, $id = NULL, $uuid = NULL)
    {
        $this->method = $method;
        $this->class = '\\App\\Payments\\' . $this->method;
        if (!class_exists($this->class)) abort(500, 'gate is not found');
        if ($id) $payment = Payment::find($id)->toArray();
        if ($uuid) $payment = Payment::where('uuid', $uuid)->first()->toArray();
        $this->config = [];
        if (isset($payment)) {
            $this->config = $payment['config'];
            $this->config['enable'] = $payment['enable'];
            $this->config['id'] = $payment['id'];
            $this->config['uuid'] = $payment['uuid'];
            $this->config['notify_domain'] = $payment['notify_domain'];
        };
        $this->payment = new $this->class($this->config);
    }

    public function notify($params)
    {
        if (!$this->config['enable']) abort(500, 'gate is not enable');
        return $this->payment->notify($params);
    }

    public function pay($order)
    {
        // Prefer configured app_url to avoid generating internal container host in URLs.
        $baseUrl = rtrim(config('v2board.app_url') ?: url('/'), '/');
        $notifyPath = "/api/v1/guest/payment/notify/{$this->method}/{$this->config['uuid']}";
        $notifyUrl = $baseUrl . $notifyPath;
        if ($this->config['notify_domain']) {
            $notifyUrl = rtrim($this->config['notify_domain'], '/') . $notifyPath;
        }
        $currentBase = $this->currentBase();
        if ($currentBase) { 
            $returnUrl = $currentBase . '/#/order/' . $order['trade_no'];
        } else {
            $returnUrl = url('/#/order/' . $order['trade_no']);
        }
        return $this->payment->pay([
            'notify_url' => $notifyUrl,
<<<<<<< HEAD
            'return_url' => $baseUrl . '/#/order/' . $order['trade_no'],
=======
            'return_url' => $returnUrl,
>>>>>>> upstream/master
            'trade_no' => $order['trade_no'],
            'total_amount' => $order['total_amount'],
            'user_id' => $order['user_id'],
            'stripe_token' => $order['stripe_token']
        ]);
    }

    public function form()
    {
        $form = $this->payment->form();
        $keys = array_keys($form);
        foreach ($keys as $key) {
            if (isset($this->config[$key])) $form[$key]['value'] = $this->config[$key];
        }
        return $form;
    }

    private function currentBase()
    {
        $origin = request()->header('Origin');

        if ($origin && preg_match('#^https?://[A-Za-z0-9.\-:\[\]]+$#i', $origin)) {
            return rtrim($origin, '/');
        }

        $host = request()->header('X-Forwarded-Host')
            ?: request()->header('X-Original-Host')
            ?: request()->header('Host');

        if ($host) {
            $host = trim(explode(',', $host)[0]);

            if (preg_match('/^[A-Za-z0-9.\-:\[\]]+$/', $host)) {
                $scheme = request()->header('X-Forwarded-Proto')
                    ?: (request()->secure() ? 'https' : 'http');
                return $scheme . '://' . $host;
            }
        }

        return '';
    }
}
