{{-- Promo code entry for the cart and checkout summaries. Behaviour lives in public/js/promo.js. --}}
@if(!empty($showPromoBox))
  <div class="promo-box" data-promo-box style="margin:.75rem 0">
    @if(!empty($promoError))
      <p class="promo-box__msg" role="alert" style="color:#b42318;margin:0 0 .5rem;font-size:.9rem">{{ $promoError }}</p>
    @endif

    @if(!empty($promoCode))
      <div class="promo-box__applied" style="display:flex;justify-content:space-between;align-items:center;gap:.5rem">
        <span><i class="fas fa-tag" aria-hidden="true"></i> Code <strong>{{ $promoCode }}</strong> applied</span>
        <button type="button" class="btn" data-promo-remove style="padding:.25rem .75rem">Remove</button>
      </div>
    @else
      <form data-promo-form style="display:flex;gap:.5rem" novalidate>
        <label for="promo-code-input" class="sr-only" style="position:absolute;left:-9999px">Promo code</label>
        <input type="text" id="promo-code-input" name="code" maxlength="40" autocomplete="off"
               placeholder="Promo code" style="flex:1;min-width:0;text-transform:uppercase;padding:.5rem .75rem">
        <button type="submit" class="btn" data-promo-apply>Apply</button>
      </form>
      <p class="promo-box__msg" data-promo-msg role="alert" style="color:#b42318;margin:.5rem 0 0;font-size:.9rem" hidden></p>
    @endif
  </div>
@endif
