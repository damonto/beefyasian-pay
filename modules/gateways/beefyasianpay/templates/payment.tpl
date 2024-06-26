<script src="https://cdn.jsdelivr.net/gh/davidshimjs/qrcodejs@master/qrcode.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/clipboard@2.0.8/dist/clipboard.min.js"></script>
<style>
    .payment-btn-container {
        display: flex;
        justify-content: center;
    }

    #qrcode {
        display: flex;
        width: 100%;
        justify-content: center;
        margin-top: 10px;
    }

    .address {
        width: 100%;
        border: 1px solid #eee;
        padding: 5px;
        border-radius: 4px;
        margin-top: 20px;
    }

    .copy-botton {
        width: 100%;
    }

    .copy-botton .btn {
        width: 100%;
    }

    .valid-till {
        margin-top: 10px;
    }
    .amount-display {
        font-size: 1.5em; /* 放大金额显示 */
        color: red; /* 设置金额颜色为红色 */
    }
    .warning {
        font-size: 1em;
        color: black;
    }
</style>

<div style="width: 250px">
    <select id="chain" class="custom-select">
        {foreach from=$supportedChains key=value item=name}

            <option value="{$value}" {($chain===$value) ? 'selected' : ''}>{$name}</option>
        {/foreach}
    </select>
    <div id="qrcode"></div>
    <p class="usdt-addr">
        <input id="address" class="address" value="{$address}"></input>
        {* if amount is 0, do not display amount *}
        {if $amount != 0}
        <p class="amount-display"><span id="amount">{$amount}</span> {$value} USDT</p>
        <p class="warning"> 警告! 请确保向 {$value} 链地址转指定金额（{$amount}  USDT), 否则支付可能不会成功。</p>
        <p class="warning"> Warning! Please make sure to transfer exact {$amount} USDT to the {$value} chain address, otherwise the payment may not be successful.</p>
        <p class="warning"> Внимание! Пожалуйста, убедитесь, что вы переводите ровно {$amount} USDT на адрес сети {$value}, иначе платеж может не пройти успешно.</p>
        {/if}
        <div class="copy-botton">
            <button id="clipboard-btn" class="btn btn-primary" type="button" data-clipboard-target="#address">COPY</button>
        </div>
        <p class="valid-till">Valid till <span id="valid-till">{$validTill}</span></p>
    </p>
</div>

<script>
    const clipboard = new ClipboardJS('#clipboard-btn')
    clipboard.on('success', () => {
        $('#clipboard-btn').text('COPIED')
        setTimeout(() => {
            $('#clipboard-btn').text('COPY')
        }, 500);
    })

    new QRCode(document.querySelector('#qrcode'), {
        text: "{$address}",
        width: 250,
        height: 250,
    })

    $('#clipboard-btn').hover(() => {
        $('#clipboard-btn').text('COPY')
    })

    $('#chain').on('change', () => {
        const value = $('#chain').val()
        fetch(window.location.href + '&act=switch_chain&chain=' + value)
            .then(r => r.json())
            .then(r => {
                if (r.status) {
                    window.location.reload(true)
                }
            })
    })

    window.localStorage.removeItem('whmcs_usdt_invoice')
    setInterval(() => {
        $('#clipboard-btn').text('UPDATING')
        fetch(window.location.href + '&act=invoice_status')
            .then(r => r.json())
            .then(r => {
                const previous = JSON.parse(window.localStorage.getItem(`whmcs_usdt_invoice`) || '{}')
                window.localStorage.setItem('whmcs_usdt_invoice', JSON.stringify(r))
                if (r.status.toLowerCase() === 'paid' || (previous.amountin !== undefined && previous?.amountin !== r.amountin)) {
                    $('#clipboard-btn').text('ADDING PMT')

                    setTimeout(() => {
                        window.location.reload(true)
                    }, 1000);
                } else if (!r.status) {
                    alert(r.error)
                } else {
                    document.querySelector('#valid-till').innerHTML = r.valid_till
                }

                setTimeout(() => {
                    $('#clipboard-btn').text('UPDATED')
                    setTimeout(() => {
                        $('#clipboard-btn').text('COPY')
                    }, 1000)
                }, 1000)
            })
            .catch(e => window.location.reload(true))

    }, 15000);
</script>
