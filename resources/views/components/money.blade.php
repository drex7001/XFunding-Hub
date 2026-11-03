@props(['currency', 'amount'])
<span class="money">{{ $currency }} {{ \App\Models\FundingRequest::groupDecimal($amount) }}</span>
