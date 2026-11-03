{{--
    "Book a new appointment", at the foot of the tracking and review pages
    once the booking is over. One partial, so both look and say the same.

    Expects $link from Clinic::bookAgainUrl(); draws nothing when it is null.
    An outline rather than a filled button, so it never outranks the page's
    own action — sending the review.
--}}
@if ($link)
    <style>
        .book-again {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            min-height: 48px;
            margin-bottom: 12px;
            padding: 0 14px;
            border-radius: 12px;
            background: #FFFFFF;
            border: 1px solid #185FA5;
            color: #185FA5;
            font-size: 15px;
            font-weight: 800;
            text-decoration: none;
        }

        .book-again:hover { background: #EEF4FB; }
        .book-again svg { flex: 0 0 auto; }
    </style>

    <a class="book-again" href="{{ $link }}">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="3"/><path d="M8 3v4M16 3v4M3 11h18M12 14v4M10 16h4"/></svg>
        {{ __('booking.tracking.book_again') }}
    </a>
@endif
