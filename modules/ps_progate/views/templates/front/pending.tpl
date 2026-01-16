{extends file='page.tpl'}

{block name='page_title'}
    <h1>{l s='Account Validation Pending' mod='progate'}</h1>
{/block}

{block name='page_content'}
    <div class="progate-pending-page">
        <div class="alert alert-info">
            <h3>{l s='Thank you for your registration!' mod='progate'}</h3>
            <p>{l s='Your account has been successfully created on %shop_name%.' sprintf=[$shop_name] mod='progate'}</p>
            <p><strong>{l s='Commercial validation in progress' mod='progate'}</strong></p>
            <p>{l s='Your account is currently pending commercial validation. Our team will review your registration and activate your access as soon as possible.' mod='progate'}</p>
            <p>{l s='You will receive an email notification once your account has been validated.' mod='progate'}</p>
        </div>

        <div class="progate-info-box">
            <h4>{l s='What happens next?' mod='progate'}</h4>
            <ul>
                <li>{l s='Our commercial team will review your registration details' mod='progate'}</li>
                <li>{l s='You will receive an email confirmation once your account is validated' mod='progate'}</li>
                <li>{l s='After validation, you will have full access to our professional catalog' mod='progate'}</li>
            </ul>
        </div>

        <div class="progate-contact-box">
            <h4>{l s='Need assistance?' mod='progate'}</h4>
            <p>{l s='If you have any questions about your registration or need immediate assistance, please contact our commercial team.' mod='progate'}</p>
            <p>
                <a href="{$contact_url}" class="btn btn-primary">
                    {l s='Contact us' mod='progate'}
                </a>
            </p>
        </div>
    </div>
{/block}

{block name='page_footer'}
    <style>
        .progate-pending-page {
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
        }
        .progate-pending-page .alert-info {
            background-color: #d9edf7;
            border-color: #bce8f1;
            color: #31708f;
            padding: 20px;
            margin-bottom: 30px;
            border-radius: 4px;
        }
        .progate-pending-page h3 {
            margin-top: 0;
            color: #31708f;
        }
        .progate-info-box,
        .progate-contact-box {
            background: #f9f9f9;
            padding: 20px;
            margin-bottom: 20px;
            border-left: 4px solid #2fb5d2;
            border-radius: 4px;
        }
        .progate-info-box h4,
        .progate-contact-box h4 {
            margin-top: 0;
            color: #333;
        }
        .progate-info-box ul {
            padding-left: 20px;
        }
        .progate-info-box li {
            margin-bottom: 10px;
            line-height: 1.6;
        }
    </style>
{/block}