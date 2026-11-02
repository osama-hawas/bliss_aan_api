<?php

namespace App\Enums;

/**
 * Admin permissions, split into view / edit / sensitive action (docs/02-database-plan.md §10).
 */
enum Permission: string
{
    case DashboardView = 'dashboard.view';
    case ClientsView = 'clients.view';
    case ClientsEdit = 'clients.edit';
    case ClientsSuspend = 'clients.suspend';
    case ProvidersView = 'providers.view';
    case ProvidersReview = 'providers.review';
    case ProvidersSuspend = 'providers.suspend';
    case ProvidersVerify = 'providers.verify';
    case CatalogManage = 'catalog.manage';
    case BookingsView = 'bookings.view';
    case BookingsIntervene = 'bookings.intervene';
    case CancellationsDecide = 'cancellations.decide';
    case RefundsProcess = 'refunds.process';
    case SupportView = 'support.view';
    case SupportReply = 'support.reply';
    case SupportAssign = 'support.assign';
    case ChatsViewForTicket = 'chats.view_for_ticket';
    case ReviewsModerate = 'reviews.moderate';
    case ProductsManage = 'products.manage';
    case OrdersView = 'orders.view';
    case OrdersUpdateStatus = 'orders.update_status';
    case DeliveryAssigned = 'delivery.assigned';
    case FinanceView = 'finance.view';
    case FinanceProcess = 'finance.process';
    case PayoutsProcess = 'payouts.process';
    case ContentManage = 'content.manage';
    case NotificationsBroadcast = 'notifications.broadcast';
    case SettingsManage = 'settings.manage';
    case RolesManage = 'roles.manage';
    case AuditView = 'audit.view';
    case ReportsExport = 'reports.export';
}
