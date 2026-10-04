import {
    Baby, Banknote, BookOpen, Briefcase, Bus, Cake, Car, Circle, Clapperboard, Coffee, Coins, CreditCard, Dumbbell,
    Fuel, Gamepad2, Gem, Gift, GraduationCap, HandCoins, HandHeart, HeartPulse, House, Landmark, Laptop, Music,
    PawPrint, PiggyBank, Pill, Plane, Receipt, Scissors, Shapes, Shirt, ShoppingBag, ShoppingCart, Smartphone,
    Sparkles, Store, Ticket, TrendingUp, Utensils, Wallet, Wifi, Wrench, Zap, Droplets, Train, Bike,
} from 'lucide-vue-next';

/** Ikon yang bisa dipilih untuk kategori (disimpan sebagai nama kebab-case). */
export const CATEGORY_ICONS = {
    utensils: Utensils,
    coffee: Coffee,
    'shopping-bag': ShoppingBag,
    'shopping-cart': ShoppingCart,
    car: Car,
    bus: Bus,
    train: Train,
    bike: Bike,
    fuel: Fuel,
    plane: Plane,
    receipt: Receipt,
    zap: Zap,
    droplets: Droplets,
    wifi: Wifi,
    smartphone: Smartphone,
    house: House,
    wrench: Wrench,
    'heart-pulse': HeartPulse,
    pill: Pill,
    dumbbell: Dumbbell,
    scissors: Scissors,
    shirt: Shirt,
    sparkles: Sparkles,
    clapperboard: Clapperboard,
    ticket: Ticket,
    music: Music,
    'gamepad-2': Gamepad2,
    'book-open': BookOpen,
    'graduation-cap': GraduationCap,
    laptop: Laptop,
    baby: Baby,
    'paw-print': PawPrint,
    cake: Cake,
    gift: Gift,
    'hand-heart': HandHeart,
    briefcase: Briefcase,
    store: Store,
    'trending-up': TrendingUp,
    'piggy-bank': PiggyBank,
    coins: Coins,
    'hand-coins': HandCoins,
    gem: Gem,
    shapes: Shapes,
};

export const categoryIcon = (name) => CATEGORY_ICONS[name] ?? Circle;

export const ACCOUNT_TYPES = {
    cash: { label: 'Tunai', icon: Banknote },
    bank: { label: 'Bank', icon: Landmark },
    ewallet: { label: 'E-wallet', icon: Smartphone },
    other: { label: 'Lainnya', icon: Wallet },
};

export const accountIcon = (type) => ACCOUNT_TYPES[type]?.icon ?? CreditCard;
