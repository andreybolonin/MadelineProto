## Method: messages.getMessageEditData  

### Parameters:

| Name     |    Type       | Required |
|----------|:-------------:|---------:|
|peer|[InputPeer](../types/InputPeer.md) | Required|
|id|[int](../types/int.md) | Required|


### Return type: [messages\_MessageEditData](../types/messages\_MessageEditData.md)

### Example:


```
$MadelineProto = new \danog\MadelineProto\API();
if (isset($token)) {
    $this->bot_login($token);
}
if (isset($number)) {
    $sentCode = $MadelineProto->phone_login($number);
    echo 'Enter the code you received: ';
    $code = '';
    for ($x = 0; $x < $sentCode['type']['length']; $x++) {
        $code .= fgetc(STDIN);
    }
    $MadelineProto->complete_phone_login($code);
}

$messages_MessageEditData = $MadelineProto->messages->getMessageEditData(['peer' => InputPeer, 'id' => int, ]);
```